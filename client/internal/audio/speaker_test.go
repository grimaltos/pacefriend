package audio

import (
	"context"
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"

	"github.com/pacefriend/client/internal/coaching"
)

func buildTinyWAV() []byte {
	pcm := make([]byte, 64)
	buf := make([]byte, 44+len(pcm))
	copy(buf[0:4], "RIFF")
	binary.LittleEndian.PutUint32(buf[4:8], uint32(36+len(pcm)))
	copy(buf[8:12], "WAVE")
	copy(buf[12:16], "fmt ")
	binary.LittleEndian.PutUint32(buf[16:20], 16)
	binary.LittleEndian.PutUint16(buf[20:22], 1)
	binary.LittleEndian.PutUint16(buf[22:24], 1)
	binary.LittleEndian.PutUint32(buf[24:28], 22050)
	binary.LittleEndian.PutUint32(buf[28:32], 44100)
	binary.LittleEndian.PutUint16(buf[32:34], 2)
	binary.LittleEndian.PutUint16(buf[34:36], 16)
	copy(buf[36:40], "data")
	binary.LittleEndian.PutUint32(buf[40:44], uint32(len(pcm)))
	return buf
}

func TestEnginePreloadsIntoRAMDeduplicatesAndVerifiesSHA256(t *testing.T) {
	wav := buildTinyWAV()
	sum := sha256.Sum256(wav)
	validHash := hex.EncodeToString(sum[:])

	var hits atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		hits.Add(1)
		w.Header().Set("Content-Type", "audio/wav")
		_, _ = w.Write(wav)
	}))
	defer srv.Close()

	eng := NewEngine(Config{Muted: true})
	defer eng.Close()

	plan := &coaching.Plan{
		PlanID: "p1",
		Triggers: []coaching.Trigger{
			{
				ID:          "t1_pre",
				Turn:        1,
				Phase:       coaching.PhasePreBrake,
				TriggerPct:  0.96,
				Text:        "Carlos, frena diez metros más tarde.",
				AudioURL:    srv.URL + "/api/v1/audio/" + validHash,
				AudioSHA256: validHash,
			},
		},
	}

	if err := eng.Preload(context.Background(), plan); err != nil {
		t.Fatalf("Preload failed: %v", err)
	}
	if eng.CachedCount() != 1 || hits.Load() != 1 {
		t.Fatalf("expected 1 cached clip and 1 HTTP hit, got cached=%d hits=%d", eng.CachedCount(), hits.Load())
	}

	// Second preload of same hash must hit RAM cache without network request
	if err := eng.Preload(context.Background(), plan); err != nil {
		t.Fatalf("second Preload failed: %v", err)
	}
	if hits.Load() != 1 {
		t.Fatalf("expected 0 extra HTTP requests on cached clip, got total hits=%d", hits.Load())
	}

	// Corrupted SHA256 must fail integrity check
	badPlan := &coaching.Plan{
		PlanID: "bad",
		Triggers: []coaching.Trigger{
			{
				ID:          "bad",
				AudioURL:    srv.URL + "/other",
				AudioSHA256: "0000000000000000000000000000000000000000000000000000000000000000",
			},
		},
	}
	if err := eng.Preload(context.Background(), badPlan); err == nil {
		t.Fatal("expected SHA-256 mismatch error, got nil")
	}
}

func TestEngineSpeakZeroAllocs(t *testing.T) {
	eng := NewEngine(Config{Muted: true})
	defer eng.Close()

	trg := coaching.Trigger{
		ID:         "t1",
		Turn:       1,
		Phase:      coaching.PhasePreBrake,
		TriggerPct: 0.50,
		Text:       "Abre gas.",
	}

	allocs := testing.AllocsPerRun(1000, func() {
		eng.Speak(trg)
	})
	if allocs != 0 {
		t.Fatalf("expected 0 heap allocations in Engine.Speak, got %f", allocs)
	}
}
