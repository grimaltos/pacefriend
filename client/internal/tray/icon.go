package tray

import (
	_ "embed"
	"encoding/binary"
	"errors"
)

// DefaultIconICO embeds client/internal/tray/icon.ico directly into the static binary.
// Replace icon.ico with your official brand .ico anytime and rebuild.
//
//go:embed icon.ico
var DefaultIconICO []byte

var ErrInvalidICO = errors.New("tray: invalid .ico header")

// Options configures the System Tray controller callbacks.
type Options struct {
	InitialStatus string
	PanelURL      string
	IsMuted       func() bool
	OnToggleMute  func()
	OnTestRadio   func()
	OnExit        func()
}

// ExtractFirstICOImage parses a standard Win32 .ico container and returns the raw
// DIB/PNG resource payload suitable for CreateIconFromResourceEx.
func ExtractFirstICOImage(ico []byte) ([]byte, error) {
	if len(ico) < 22 {
		return nil, ErrInvalidICO
	}
	reserved := binary.LittleEndian.Uint16(ico[0:2])
	icoType := binary.LittleEndian.Uint16(ico[2:4])
	count := binary.LittleEndian.Uint16(ico[4:6])
	if reserved != 0 || icoType != 1 || count == 0 {
		return nil, ErrInvalidICO
	}

	// Pick entry with largest byte size (highest resolution/color depth)
	var bestOffset, bestSize uint32
	for i := 0; i < int(count); i++ {
		entryPos := 6 + i*16
		if entryPos+16 > len(ico) {
			break
		}
		bytesInRes := binary.LittleEndian.Uint32(ico[entryPos+8 : entryPos+12])
		imageOffset := binary.LittleEndian.Uint32(ico[entryPos+12 : entryPos+16])
		if imageOffset >= 22 && uint64(imageOffset)+uint64(bytesInRes) <= uint64(len(ico)) {
			if bytesInRes >= bestSize {
				bestSize = bytesInRes
				bestOffset = imageOffset
			}
		}
	}

	if bestSize == 0 {
		return nil, ErrInvalidICO
	}
	return ico[bestOffset : bestOffset+bestSize], nil
}
