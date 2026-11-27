package audio

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/pacefriend/client/internal/coaching"
)

const (
	MaxCachedClips = 48
	maxAudioBytes  = 8 << 20 // 8 MB safety cap per voice clip
)

type clipFormat uint8

const (
	formatWAV clipFormat = iota + 1
	formatMP3
)

type cachedClip struct {
	key        string
	format     clipFormat
	data       []byte
	stagedPath string
}

// Config configures the RAM-preloaded local voice playback engine.
type Config struct {
	Muted      bool
	Volume     int // 0..100 (default 85 if <= 0 and not explicitly 0)
	APIToken   string
	HTTPClient *http.Client
	Logger     *slog.Logger
}

// Engine implements coaching.Speaker and coaching.ImmediateSpeaker with background HTTP
// preloading into RAM, SHA-256 integrity verification, independent software volume scaling,
// and a non-blocking playback queue (0 allocs in the 20 Hz telemetry loop).
type Engine struct {
	muted      atomic.Bool
	volumePct  atomic.Int32
	apiToken   string
	httpClient *http.Client
	logger     *slog.Logger
	stageDir   string

	mu    sync.RWMutex
	clips map[string]cachedClip
	order []string

	playCh    chan coaching.Trigger
	scaledBuf []byte // Owned exclusively by playbackLoop goroutine for zero-lock WAV volume scaling
	wg        sync.WaitGroup
}

// NewEngine creates and starts the background audio worker goroutine.
func NewEngine(cfg Config) *Engine {
	httpClient := cfg.HTTPClient
	if httpClient == nil {
		httpClient = &http.Client{Timeout: 12 * time.Second}
	}
	stageDir := filepath.Join(os.TempDir(), "pacefriend-audio")
	_ = os.MkdirAll(stageDir, 0o700)

	initialVol := cfg.Volume
	if initialVol <= 0 {
		initialVol = 85
	}
	if initialVol > 100 {
		initialVol = 100
	}

	e := &Engine{
		apiToken:   strings.TrimSpace(cfg.APIToken),
		httpClient: httpClient,
		logger:     cfg.Logger,
		stageDir:   stageDir,
		clips:      make(map[string]cachedClip, MaxCachedClips),
		order:      make([]string, 0, MaxCachedClips),
		playCh:     make(chan coaching.Trigger, 6),
	}
	e.muted.Store(cfg.Muted)
	e.volumePct.Store(int32(initialVol))

	e.wg.Add(1)
	go e.playbackLoop()
	return e
}

// Close drains/stops the background playback worker and cleans staged temp files.
func (e *Engine) Close() {
	close(e.playCh)
	e.wg.Wait()
	if e.stageDir != "" {
		_ = os.RemoveAll(e.stageDir)
	}
}

// SetMuted atomically mutes or unmutes radio audio playback (e.g. from the System Tray).
func (e *Engine) SetMuted(muted bool) {
	e.muted.Store(muted)
}

// IsMuted reports whether radio playback is currently silenced.
func (e *Engine) IsMuted() bool {
	return e.muted.Load()
}

// SetVolume atomically sets the independent PaceFriend radio volume percentage (0..100).
func (e *Engine) SetVolume(pct int) {
	if pct < 0 {
		pct = 0
	} else if pct > 100 {
		pct = 100
	}
	e.volumePct.Store(int32(pct))
}

// Volume returns the current independent radio volume percentage (0..100).
func (e *Engine) Volume() int {
	return int(e.volumePct.Load())
}

// CachedCount returns the number of voice clips currently resident in RAM.
func (e *Engine) CachedCount() int {
	e.mu.RLock()
	defer e.mu.RUnlock()
	return len(e.clips)
}

// Preload fetches any missing AI voice clips in the coaching plan into RAM before
// the spatial triggers become active on track.
func (e *Engine) Preload(ctx context.Context, plan *coaching.Plan) error {
	if plan == nil {
		return nil
	}

	var firstErr error
	for _, trg := range plan.Triggers {
		if trg.AudioURL == "" {
			continue
		}
		if err := e.preloadSingle(ctx, trg); err != nil && firstErr == nil {
			firstErr = err
		}
	}
	return firstErr
}

// PlayCallouts preloads immediate non-spatial radio callouts (greeting, finish-line time,
// fuel/tyre check) and queues them for immediate playback in order.
func (e *Engine) PlayCallouts(ctx context.Context, callouts []coaching.Callout) error {
	var firstErr error
	for _, c := range callouts {
		trg := coaching.Trigger{
			ID:          c.ID,
			Phase:       coaching.Phase("radio_callout"),
			Text:        c.Text,
			AudioURL:    c.AudioURL,
			AudioSHA256: c.AudioSHA256,
		}
		if trg.AudioURL != "" {
			if err := e.preloadSingle(ctx, trg); err != nil {
				if firstErr == nil {
					firstErr = err
				}
				continue
			}
		}
		e.Speak(trg)
	}
	return firstErr
}

// Speak enqueues a spatial or immediate trigger for playback without blocking
// or allocating on the heap inside the 20 Hz telemetry sampling loop.
func (e *Engine) Speak(trg coaching.Trigger) {
	select {
	case e.playCh <- trg:
	default:
		// Queue full; skip overlap cleanly rather than stalling telemetry.
	}
}

func (e *Engine) preloadSingle(ctx context.Context, trg coaching.Trigger) error {
	key := clipKey(trg)

	e.mu.RLock()
	_, exists := e.clips[key]
	e.mu.RUnlock()
	if exists {
		return nil
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, trg.AudioURL, nil)
	if err != nil {
		return err
	}
	if e.apiToken != "" {
		req.Header.Set("Authorization", "Bearer "+e.apiToken)
	}

	resp, err := e.httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("audio HTTP %d for %s", resp.StatusCode, trg.ID)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, maxAudioBytes))
	if err != nil {
		return err
	}
	if len(raw) < 16 {
		return errors.New("audio payload too small")
	}

	if len(trg.AudioSHA256) == 64 {
		sum := sha256.Sum256(raw)
		gotHex := hex.EncodeToString(sum[:])
		if !strings.EqualFold(gotHex, trg.AudioSHA256) {
			return fmt.Errorf("audio sha256 mismatch: want %s got %s", trg.AudioSHA256, gotHex)
		}
	}

	format := detectFormat(raw)
	stagedPath, err := e.stageClipIfNeeded(key, format, raw)
	if err != nil && e.logger != nil {
		e.logger.Warn("failed to stage audio clip", slog.Any("err", err))
	}

	clip := cachedClip{
		key:        key,
		format:     format,
		data:       raw,
		stagedPath: stagedPath,
	}

	e.mu.Lock()
	if _, already := e.clips[key]; !already {
		if len(e.order) >= MaxCachedClips {
			oldest := e.order[0]
			e.order = e.order[1:]
			if evicted, ok := e.clips[oldest]; ok && evicted.stagedPath != "" {
				_ = os.Remove(evicted.stagedPath)
			}
			delete(e.clips, oldest)
		}
		e.order = append(e.order, key)
	}
	e.clips[key] = clip
	e.mu.Unlock()

	return nil
}

func (e *Engine) playbackLoop() {
	defer e.wg.Done()
	for trg := range e.playCh {
		key := clipKey(trg)
		e.mu.RLock()
		clip, hasClip := e.clips[key]
		e.mu.RUnlock()

		vol := int(e.volumePct.Load())

		if e.logger != nil {
			e.logger.Info("RADIO ENGINEER",
				slog.Int("turn", trg.Turn),
				slog.String("phase", string(trg.Phase)),
				slog.Float64("pct", float64(trg.TriggerPct)),
				slog.Bool("ram_audio", hasClip),
				slog.Int("volume", vol),
				slog.String("message", trg.Text),
			)
		}

		if e.muted.Load() || vol <= 0 {
			continue
		}

		if hasClip {
			playClip := clip
			if clip.format == formatWAV && vol < 99 {
				playClip.data = scaleWavPCM16(clip.data, vol, &e.scaledBuf)
			}
			if err := playPlatformClip(playClip, vol); err != nil && e.logger != nil {
				e.logger.Warn("audio playback warning", slog.Any("err", err))
			}
		} else {
			_ = speakPlatformFallback(trg.Text)
		}
	}
}

// scaleWavPCM16 scales 16-bit signed LE PCM samples in a standard WAV buffer by volPct/100
// into a reusable buffer without touching iRacing's global Windows volume mixer.
func scaleWavPCM16(wav []byte, volPct int, dst *[]byte) []byte {
	if len(wav) <= 44 || volPct >= 100 {
		return wav
	}
	if cap(*dst) < len(wav) {
		*dst = make([]byte, len(wav))
	} else {
		*dst = (*dst)[:len(wav)]
	}
	out := *dst
	copy(out[:44], wav[:44])

	// Locate "data" chunk offset safely (standard is byte 36 -> samples at 44)
	dataStart := 44
	for pos := 12; pos+8 <= len(wav) && pos < 128; {
		chunkID := wav[pos : pos+4]
		chunkSize := int(binary.LittleEndian.Uint32(wav[pos+4 : pos+8]))
		if bytes.Equal(chunkID, []byte("data")) {
			dataStart = pos + 8
			copy(out[:dataStart], wav[:dataStart])
			break
		}
		if chunkSize < 0 {
			break
		}
		pos += 8 + chunkSize
	}

	for i := dataStart; i+1 < len(wav); i += 2 {
		s := int32(int16(binary.LittleEndian.Uint16(wav[i : i+2])))
		scaled := (s * int32(volPct)) / 100
		binary.LittleEndian.PutUint16(out[i:i+2], uint16(int16(scaled)))
	}
	return out
}

func (e *Engine) stageClipIfNeeded(key string, format clipFormat, data []byte) (string, error) {
	if !requiresStagedFile(format) || e.stageDir == "" {
		return "", nil
	}
	ext := ".mp3"
	if format == formatWAV {
		ext = ".wav"
	}
	path := filepath.Join(e.stageDir, key+ext)
	if err := os.WriteFile(path, data, 0o600); err != nil {
		return "", err
	}
	return path, nil
}

func clipKey(trg coaching.Trigger) string {
	if len(trg.AudioSHA256) == 64 {
		return strings.ToLower(trg.AudioSHA256)
	}
	sum := sha256.Sum256([]byte(trg.AudioURL))
	return hex.EncodeToString(sum[:])
}

func detectFormat(data []byte) clipFormat {
	if len(data) >= 12 && bytes.Equal(data[0:4], []byte("RIFF")) && bytes.Equal(data[8:12], []byte("WAVE")) {
		return formatWAV
	}
	return formatMP3
}
