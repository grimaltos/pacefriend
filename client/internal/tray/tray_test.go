package tray

import "testing"

func TestEmbeddedPlaceholderIconParsesCleanly(t *testing.T) {
	if len(DefaultIconICO) < 100 {
		t.Fatalf("expected embedded icon.ico bytes, got %d", len(DefaultIconICO))
	}
	img, err := ExtractFirstICOImage(DefaultIconICO)
	if err != nil {
		t.Fatalf("ExtractFirstICOImage failed: %v", err)
	}
	if len(img) != 40+32*32*4+128 {
		t.Fatalf("expected 4264-byte 32x32 32-bit DIB payload, got %d", len(img))
	}
}
