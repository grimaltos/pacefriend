//go:build windows

package audio

import (
	"fmt"
	"syscall"
	"unsafe"
)

const (
	sndSync      = 0x0000
	sndNoDefault = 0x0002
	sndMemory    = 0x0004
)

var (
	winmm             = syscall.NewLazyDLL("winmm.dll")
	procPlaySoundW    = winmm.NewProc("PlaySoundW")
	procMciSendString = winmm.NewProc("mciSendStringW")
)

func requiresStagedFile(format clipFormat) bool {
	// WAV plays directly out of Go's RAM slice via PlaySoundW(SND_MEMORY).
	// MP3 (ElevenLabs) uses Win32 MCI over a pre-staged file written once during Preload.
	return format == formatMP3
}

func playPlatformClip(clip cachedClip) error {
	if clip.format == formatWAV && len(clip.data) > 0 {
		r1, _, err := procPlaySoundW.Call(
			uintptr(unsafe.Pointer(&clip.data[0])),
			0,
			uintptr(sndMemory|sndSync|sndNoDefault),
		)
		if r1 == 0 {
			return fmt.Errorf("PlaySoundW failed: %w", err)
		}
		return nil
	}

	if clip.stagedPath != "" {
		_ = mciCommand(`close pacefriend_clip`)
		if err := mciCommand(fmt.Sprintf(`open "%s" type mpegvideo alias pacefriend_clip`, clip.stagedPath)); err != nil {
			return err
		}
		defer func() { _ = mciCommand(`close pacefriend_clip`) }()
		return mciCommand(`play pacefriend_clip wait`)
	}

	return nil
}

func speakPlatformFallback(_ string) error {
	return nil
}

func mciCommand(cmd string) error {
	utf16Cmd, err := syscall.UTF16PtrFromString(cmd)
	if err != nil {
		return err
	}
	r1, _, _ := procMciSendString.Call(
		uintptr(unsafe.Pointer(utf16Cmd)),
		0,
		0,
		0,
	)
	if r1 != 0 {
		return fmt.Errorf("mciSendString error code %d", r1)
	}
	return nil
}
