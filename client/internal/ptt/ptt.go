package ptt

import (
	"context"
	"encoding/binary"
	"log/slog"
	"math"
	"sync"
	"sync/atomic"
	"time"

	"github.com/pacefriend/client/internal/config"
)

const (
	SampleRateHz   = 16000
	MaxRecordDur   = 6 * time.Second
	MinRecordBytes = (SampleRateHz * 2 * 300) / 1000 // >= 300 ms to ignore accidental micro-taps
)

// OnRecordingFunc is invoked on a background goroutine whenever the driver releases
// the Push-To-Talk button after speaking a question or command.
type OnRecordingFunc func(ctx context.Context, wavBytes []byte)

// Listener monitors the driver's configured steering-wheel / button-box / keyboard PTT button
// and captures 16 kHz mono WAV audio while held.
type Listener struct {
	mu          sync.RWMutex
	binding     config.PTTConfig
	capturing   atomic.Bool
	onRecording OnRecordingFunc
	playTone    func(wav []byte)
	logger      *slog.Logger

	openBeepWAV    []byte
	closeBeepWAV   []byte
	confirmBeepWAV []byte
}

// NewListener constructs a Push-To-Talk controller bound to the local configuration.
func NewListener(initial config.PTTConfig, onRecording OnRecordingFunc, playTone func(wav []byte), logger *slog.Logger) *Listener {
	return &Listener{
		binding:        initial,
		onRecording:    onRecording,
		playTone:       playTone,
		logger:         logger,
		openBeepWAV:    SynthesizeToneWAV(1180, 65, 0.28),
		closeBeepWAV:   SynthesizeToneWAV(780, 50, 0.22),
		confirmBeepWAV: SynthesizeDoubleBeepWAV(980, 1320, 60, 0.30),
	}
}

// Binding returns the currently active Push-To-Talk button binding.
func (l *Listener) Binding() config.PTTConfig {
	l.mu.RLock()
	defer l.mu.RUnlock()
	return l.binding
}

// SetBinding updates the active Push-To-Talk button binding in memory.
func (l *Listener) SetBinding(b config.PTTConfig) {
	l.mu.Lock()
	l.binding = b
	l.mu.Unlock()
}

// IsCapturingBinding reports whether the client is currently waiting for the user to press a wheel/keyboard button.
func (l *Listener) IsCapturingBinding() bool {
	return l.capturing.Load()
}

// Run polls the configured PTT button at 40 Hz in its own lightweight goroutine (zero impact on 20 Hz telemetry).
func (l *Listener) Run(ctx context.Context) {
	runPlatformLoop(ctx, l)
}

// LearnBindingAsync enters a 10-second capture window so the user can press any button on their
// steering wheel, button box, or keyboard right from the Windows System Tray.
func (l *Listener) LearnBindingAsync(ctx context.Context, onLearned func(config.PTTConfig, bool)) {
	if !l.capturing.CompareAndSwap(false, true) {
		return
	}
	go func() {
		defer l.capturing.Store(false)
		if l.playTone != nil {
			l.playTone(l.openBeepWAV)
		}
		captureCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
		defer cancel()

		learned, ok := captureNextPlatformInput(captureCtx)
		if ok {
			l.SetBinding(learned)
			if l.playTone != nil {
				l.playTone(l.confirmBeepWAV)
			}
		}
		if onLearned != nil {
			onLearned(learned, ok)
		}
	}()
}

// EncodePCM16MonoWAV wraps raw little-endian signed 16-bit mono PCM samples in a 44-byte RIFF WAV header.
func EncodePCM16MonoWAV(pcm []byte, sampleRate int) []byte {
	dataLen := uint32(len(pcm))
	out := make([]byte, 44+len(pcm))
	copy(out[0:4], "RIFF")
	binary.LittleEndian.PutUint32(out[4:8], 36+dataLen)
	copy(out[8:12], "WAVE")
	copy(out[12:16], "fmt ")
	binary.LittleEndian.PutUint32(out[16:20], 16)                    // PCM fmt chunk size
	binary.LittleEndian.PutUint16(out[20:22], 1)                     // AudioFormat = 1 (PCM)
	binary.LittleEndian.PutUint16(out[22:24], 1)                     // NumChannels = 1 (Mono)
	binary.LittleEndian.PutUint32(out[24:28], uint32(sampleRate))    // SampleRate
	binary.LittleEndian.PutUint32(out[28:32], uint32(sampleRate*2))  // ByteRate
	binary.LittleEndian.PutUint16(out[32:34], 2)                     // BlockAlign
	binary.LittleEndian.PutUint16(out[34:36], 16)                    // BitsPerSample
	copy(out[36:40], "data")
	binary.LittleEndian.PutUint32(out[40:44], dataLen)
	copy(out[44:], pcm)
	return out
}

// SynthesizeToneWAV builds a tiny smooth-enveloped sine-wave PTT beep WAV in memory.
func SynthesizeToneWAV(freqHz float64, durationMs int, amp float64) []byte {
	numSamples := (SampleRateHz * durationMs) / 1000
	pcm := make([]byte, numSamples*2)
	fadeSamples := SampleRateHz * 6 / 1000
	for i := 0; i < numSamples; i++ {
		env := 1.0
		if i < fadeSamples {
			env = float64(i) / float64(fadeSamples)
		} else if numSamples-i < fadeSamples {
			env = float64(numSamples-i) / float64(fadeSamples)
		}
		v := math.Sin(2.0*math.Pi*freqHz*float64(i)/float64(SampleRateHz)) * amp * env
		s16 := int16(v * 32767.0)
		binary.LittleEndian.PutUint16(pcm[i*2:i*2+2], uint16(s16))
	}
	return EncodePCM16MonoWAV(pcm, SampleRateHz)
}

// SynthesizeDoubleBeepWAV builds a pleasant two-tone confirmation chime for button binding.
func SynthesizeDoubleBeepWAV(f1, f2 float64, eachMs int, amp float64) []byte {
	n1 := (SampleRateHz * eachMs) / 1000
	gap := (SampleRateHz * 30) / 1000
	total := n1 + gap + n1
	pcm := make([]byte, total*2)
	for i := 0; i < n1; i++ {
		v1 := math.Sin(2.0*math.Pi*f1*float64(i)/float64(SampleRateHz)) * amp
		v2 := math.Sin(2.0*math.Pi*f2*float64(i)/float64(SampleRateHz)) * amp
		binary.LittleEndian.PutUint16(pcm[i*2:i*2+2], uint16(int16(v1*32767.0)))
		j := n1 + gap + i
		binary.LittleEndian.PutUint16(pcm[j*2:j*2+2], uint16(int16(v2*32767.0)))
	}
	return EncodePCM16MonoWAV(pcm, SampleRateHz)
}
