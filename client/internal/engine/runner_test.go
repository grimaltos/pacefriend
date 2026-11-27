package engine

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync"
	"testing"
	"time"

	"github.com/pacefriend/client/internal/buffer"
	"github.com/pacefriend/client/internal/coaching"
	"github.com/pacefriend/client/internal/telemetry"
	"github.com/pacefriend/client/internal/transport"
)

type stubSource struct {
	meta telemetry.SessionMetadata
}

func (s *stubSource) Connect(_ context.Context) error                    { return nil }
func (s *stubSource) Close() error                                       { return nil }
func (s *stubSource) ReadFrame(_ *telemetry.RawFrame) (bool, error)      { return false, nil }
func (s *stubSource) SessionMetadata() telemetry.SessionMetadata         { return s.meta }

func TestRunnerDiscardsOutLapAndUploadsCleanAndDirtyLaps(t *testing.T) {
	var mu sync.Mutex
	var uploaded []telemetry.CompletedLap
	var sessionEndCalled bool

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/telemetry/session/end" {
			mu.Lock()
			sessionEndCalled = true
			mu.Unlock()
			w.WriteHeader(http.StatusOK)
			return
		}
		var lap telemetry.CompletedLap
		if err := json.NewDecoder(r.Body).Decode(&lap); err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}
		mu.Lock()
		uploaded = append(uploaded, lap)
		mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"status":"ok","lap_stored":true}`))
	}))
	defer srv.Close()

	pool := buffer.NewSnapshotPool(128)
	ring := buffer.NewRingBuffer(256)
	ev := coaching.NewEvaluator(nil)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	uploader := transport.NewUploader(transport.Config{BaseURL: srv.URL}, pool, ev, nil)
	uploader.Start(ctx)

	src := &stubSource{
		meta: telemetry.SessionMetadata{
			TrackSlug:     "spa-up",
			TrackName:     "Circuit de Spa-Francorchamps",
			TrackLengthKm: 7.004,
			CarName:       "Porsche 911 GT3 R (992)",
		},
	}

	runner := NewRunner(Config{
		UserID:           "driver-42",
		SampleRateHz:     20,
		MinSamplesPerLap: 10,
	}, src, ring, pool, ev, uploader)

	baseTime := time.Now()
	simSec := 10.0

	// 1. Out-lap (Lap 0, 0.90 -> 0.99): must NOT be uploaded when crossing into Lap 1.
	for i := 0; i < 10; i++ {
		pct := float32(0.90) + float32(i)*0.01
		simSec += 0.05
		runner.ProcessFrame(baseTime, &telemetry.RawFrame{
			SessionTimeSec: simSec,
			Sample:         telemetry.Sample{Lap: 0, LapDistPct: pct, Speed: 65.0, Gear: 5},
		})
	}

	// 2. Full clean flying Lap 1 (0.00 -> 0.98, 50 samples)
	for i := 0; i < 50; i++ {
		pct := float32(i) * 0.02
		simSec += 0.05
		runner.ProcessFrame(baseTime, &telemetry.RawFrame{
			SessionTimeSec: simSec,
			Sample:         telemetry.Sample{Lap: 1, LapDistPct: pct, Speed: 70.0, Throttle: 1.0, Gear: 6},
		})
	}

	// 3. Full dirty flying Lap 2 (has OffTrack excursion mid-lap)
	for i := 0; i < 50; i++ {
		pct := float32(i) * 0.02
		simSec += 0.05
		var flags telemetry.SampleFlags
		if i == 20 {
			flags = telemetry.FlagOffTrack
		}
		runner.ProcessFrame(baseTime, &telemetry.RawFrame{
			SessionTimeSec:    simSec,
			LapLastLapTimeSec: 138.45,
			Sample:            telemetry.Sample{Lap: 2, LapDistPct: pct, Speed: 68.0, Gear: 5, Flags: flags},
		})
	}

	// Cross into Lap 3 to trigger Lap 2 upload
	simSec += 0.05
	runner.ProcessFrame(baseTime, &telemetry.RawFrame{
		SessionTimeSec:    simSec,
		LapLastLapTimeSec: 140.10,
		Sample:            telemetry.Sample{Lap: 3, LapDistPct: 0.01, Speed: 70.0, Gear: 6},
	})

	uploader.Close()

	mu.Lock()
	defer mu.Unlock()

	if len(uploaded) != 2 {
		t.Fatalf("expected 2 uploaded laps (Lap 1 and Lap 2, discarding out-lap 0), got %d", len(uploaded))
	}
	if !sessionEndCalled {
		t.Fatal("expected /api/v1/telemetry/session/end to be called on uploader.Close()")
	}
	if uploaded[0].LapNumber != 1 || !uploaded[0].IsClean || len(uploaded[0].Samples) != 50 {
		t.Fatalf("unexpected Lap 1 metadata: lap=%d clean=%v samples=%d",
			uploaded[0].LapNumber, uploaded[0].IsClean, len(uploaded[0].Samples))
	}
	if uploaded[1].LapNumber != 2 || uploaded[1].IsClean || uploaded[1].LapTimeMs != 140100 {
		t.Fatalf("unexpected Lap 2 metadata: lap=%d clean=%v timeMs=%d",
			uploaded[1].LapNumber, uploaded[1].IsClean, uploaded[1].LapTimeMs)
	}
}
