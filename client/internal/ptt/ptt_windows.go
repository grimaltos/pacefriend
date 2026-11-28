//go:build windows

package ptt

import (
	"bytes"
	"context"
	"fmt"
	"log/slog"
	"syscall"
	"time"
	"unsafe"

	"github.com/pacefriend/client/internal/config"
)

const (
	joyReturnButtons = 0x00000080
	joyErrNoError    = 0

	waveMapper     uintptr = 0xFFFFFFFF
	callbackNull   uintptr = 0x00000000
	waveFormatPCM  uint16  = 1
	whdrDone       uint32  = 0x00000001
	numRecBuffers          = 24 // 24 * 250ms = 6.0s max continuous hold
	chunkSamples           = SampleRateHz / 4
	chunkByteCount         = chunkSamples * 2
)

var (
	winmmPTT               = syscall.NewLazyDLL("winmm.dll")
	user32PTT              = syscall.NewLazyDLL("user32.dll")
	procJoyGetPosEx        = winmmPTT.NewProc("joyGetPosEx")
	procGetAsyncKeyState   = user32PTT.NewProc("GetAsyncKeyState")
	procWaveInOpen         = winmmPTT.NewProc("waveInOpen")
	procWaveInPrepareHdr   = winmmPTT.NewProc("waveInPrepareHeader")
	procWaveInUnprepareHdr = winmmPTT.NewProc("waveInUnprepareHeader")
	procWaveInAddBuffer    = winmmPTT.NewProc("waveInAddBuffer")
	procWaveInStart        = winmmPTT.NewProc("waveInStart")
	procWaveInStop         = winmmPTT.NewProc("waveInStop")
	procWaveInReset        = winmmPTT.NewProc("waveInReset")
	procWaveInClose        = winmmPTT.NewProc("waveInClose")
)

// joyInfoEx matches Win32 JOYINFOEX (52 bytes).
type joyInfoEx struct {
	dwSize        uint32
	dwFlags       uint32
	dwXpos        uint32
	dwYpos        uint32
	dwZpos        uint32
	dwRpos        uint32
	dwUpos        uint32
	dwVpos        uint32
	dwButtons     uint32
	dwButtonNumber uint32
	dwPOV         uint32
	dwReserved1   uint32
	dwReserved2   uint32
}

// waveFormatEx matches Win32 WAVEFORMATEX (18 bytes).
type waveFormatEx struct {
	wFormatTag      uint16
	nChannels       uint16
	nSamplesPerSec  uint32
	nAvgBytesPerSec uint32
	nBlockAlign     uint16
	wBitsPerSample  uint16
	cbSize          uint16
}

// waveHdr matches Win32 WAVEHDR on 64-bit Windows (48 bytes).
type waveHdr struct {
	lpData          uintptr
	dwBufferLength  uint32
	dwBytesRecorded uint32
	dwUser          uintptr
	dwFlags         uint32
	dwLoops         uint32
	lpNext          uintptr
	reserved        uintptr
}

func runPlatformLoop(ctx context.Context, l *Listener) {
	ticker := time.NewTicker(25 * time.Millisecond)
	defer ticker.Stop()

	wasDown := false
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			if l.IsCapturingBinding() {
				wasDown = false
				continue
			}
			b := l.Binding()
			down := isBindingPressed(b)
			if down && !wasDown {
				wasDown = true
				if l.playTone != nil {
					l.playTone(l.openBeepWAV)
				}
				pcm, err := recordWhileHeld(ctx, b)
				if l.playTone != nil {
					l.playTone(l.closeBeepWAV)
				}
				if err != nil {
					if l.logger != nil {
						l.logger.Warn("PTT microphone capture error", slog.Any("err", err))
					}
				} else if len(pcm) >= MinRecordBytes && l.onRecording != nil {
					wav := EncodePCM16MonoWAV(pcm, SampleRateHz)
					go l.onRecording(ctx, wav)
				}
				wasDown = false
			} else if !down {
				wasDown = false
			}
		}
	}
}

func isBindingPressed(b config.PTTConfig) bool {
	if b.DeviceType == "joystick" {
		if b.JoystickID < 0 || b.JoystickID >= 16 || b.Button < 0 || b.Button >= 32 {
			return false
		}
		var ji joyInfoEx
		ji.dwSize = uint32(unsafe.Sizeof(ji))
		ji.dwFlags = joyReturnButtons
		r1, _, _ := procJoyGetPosEx.Call(uintptr(b.JoystickID), uintptr(unsafe.Pointer(&ji)))
		if r1 != joyErrNoError {
			return false
		}
		return (ji.dwButtons & (1 << uint(b.Button))) != 0
	}

	if b.Button <= 0 {
		return false
	}
	r1, _, _ := procGetAsyncKeyState.Call(uintptr(b.Button))
	return (uint16(r1) & 0x8000) != 0
}

func recordWhileHeld(ctx context.Context, b config.PTTConfig) ([]byte, error) {
	wfx := waveFormatEx{
		wFormatTag:      waveFormatPCM,
		nChannels:       1,
		nSamplesPerSec:  SampleRateHz,
		nAvgBytesPerSec: SampleRateHz * 2,
		nBlockAlign:     2,
		wBitsPerSample:  16,
		cbSize:          0,
	}

	var hWaveIn uintptr
	r1, _, _ := procWaveInOpen.Call(
		uintptr(unsafe.Pointer(&hWaveIn)),
		waveMapper,
		uintptr(unsafe.Pointer(&wfx)),
		0,
		0,
		callbackNull,
	)
	if r1 != 0 || hWaveIn == 0 {
		return nil, fmt.Errorf("waveInOpen error %d (check Windows default microphone)", r1)
	}

	backing := make([][chunkByteCount]byte, numRecBuffers)
	hdrs := make([]waveHdr, numRecBuffers)
	hdrSize := uintptr(unsafe.Sizeof(waveHdr{}))

	for i := 0; i < numRecBuffers; i++ {
		hdrs[i] = waveHdr{
			lpData:         uintptr(unsafe.Pointer(&backing[i][0])),
			dwBufferLength: chunkByteCount,
		}
		_, _, _ = procWaveInPrepareHdr.Call(hWaveIn, uintptr(unsafe.Pointer(&hdrs[i])), hdrSize)
		_, _, _ = procWaveInAddBuffer.Call(hWaveIn, uintptr(unsafe.Pointer(&hdrs[i])), hdrSize)
	}

	_, _, _ = procWaveInStart.Call(hWaveIn)

	deadline := time.Now().Add(MaxRecordDur)
	for time.Now().Before(deadline) {
		select {
		case <-ctx.Done():
			goto stopRec
		default:
		}
		if !isBindingPressed(b) {
			// Capture trailing 90ms so final consonant isn't clipped on button release
			time.Sleep(90 * time.Millisecond)
			break
		}
		if (hdrs[numRecBuffers-1].dwFlags & whdrDone) != 0 {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}

stopRec:
	_, _, _ = procWaveInStop.Call(hWaveIn)
	_, _, _ = procWaveInReset.Call(hWaveIn)

	var out bytes.Buffer
	for i := 0; i < numRecBuffers; i++ {
		if hdrs[i].dwBytesRecorded > 0 && hdrs[i].dwBytesRecorded <= chunkByteCount {
			out.Write(backing[i][:hdrs[i].dwBytesRecorded])
		}
		_, _, _ = procWaveInUnprepareHdr.Call(hWaveIn, uintptr(unsafe.Pointer(&hdrs[i])), hdrSize)
	}
	_, _, _ = procWaveInClose.Call(hWaveIn)

	return out.Bytes(), nil
}

func captureNextPlatformInput(ctx context.Context) (config.PTTConfig, bool) {
	// Snapshot baseline held buttons so latching switches on sim button boxes do not false-trigger!
	var baselineJoy [16]uint32
	for j := 0; j < 16; j++ {
		var ji joyInfoEx
		ji.dwSize = uint32(unsafe.Sizeof(ji))
		ji.dwFlags = joyReturnButtons
		if r1, _, _ := procJoyGetPosEx.Call(uintptr(j), uintptr(unsafe.Pointer(&ji))); r1 == joyErrNoError {
			baselineJoy[j] = ji.dwButtons
		}
	}

	candidateVKs := buildBindableVKList()
	baselineVK := make(map[int]bool, len(candidateVKs))
	for _, vk := range candidateVKs {
		r1, _, _ := procGetAsyncKeyState.Call(uintptr(vk.code))
		if (uint16(r1) & 0x8000) != 0 {
			baselineVK[vk.code] = true
		}
	}

	// Brief 180ms settle after clicking tray item so mouse click release finishes cleanly
	time.Sleep(180 * time.Millisecond)

	ticker := time.NewTicker(20 * time.Millisecond)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return config.PTTConfig{}, false
		case <-ticker.C:
			// 1. Scan all 16 steering wheel / button-box joystick ports
			for j := 0; j < 16; j++ {
				var ji joyInfoEx
				ji.dwSize = uint32(unsafe.Sizeof(ji))
				ji.dwFlags = joyReturnButtons
				if r1, _, _ := procJoyGetPosEx.Call(uintptr(j), uintptr(unsafe.Pointer(&ji))); r1 == joyErrNoError {
					newlyPressed := ji.dwButtons &^ baselineJoy[j]
					if newlyPressed != 0 {
						for btn := 0; btn < 32; btn++ {
							if (newlyPressed & (1 << uint(btn))) != 0 {
								return config.PTTConfig{
									DeviceType: "joystick",
									JoystickID: j,
									Button:     btn,
									Label:      fmt.Sprintf("Volante #%d · Botón %d", j+1, btn+1),
								}, true
							}
						}
					}
					baselineJoy[j] = ji.dwButtons
				}
			}

			// 2. Scan keyboard keys & side mouse buttons
			for _, vk := range candidateVKs {
				r1, _, _ := procGetAsyncKeyState.Call(uintptr(vk.code))
				down := (uint16(r1) & 0x8000) != 0
				if down && !baselineVK[vk.code] {
					return config.PTTConfig{
						DeviceType: "keyboard",
						Button:     vk.code,
						Label:      vk.label,
					}, true
				}
				baselineVK[vk.code] = down
			}
		}
	}
}

type vkEntry struct {
	code  int
	label string
}

func buildBindableVKList() []vkEntry {
	list := []vkEntry{
		{0x05, "Ratón Lateral 1"},
		{0x06, "Ratón Lateral 2"},
		{0x09, "Tecla Tab"},
		{0x14, "Tecla Bloq Mayús"},
		{0x20, "Barra Espaciadora"},
	}
	for i := 0; i < 12; i++ {
		list = append(list, vkEntry{0x70 + i, fmt.Sprintf("Tecla F%d", i+1)})
	}
	for c := '0'; c <= '9'; c++ {
		list = append(list, vkEntry{int(c), fmt.Sprintf("Tecla %c", c)})
	}
	for c := 'A'; c <= 'Z'; c++ {
		list = append(list, vkEntry{int(c), fmt.Sprintf("Tecla %c", c)})
	}
	return list
}
