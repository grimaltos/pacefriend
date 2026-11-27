package transport

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"

	"github.com/pacefriend/client/internal/buffer"
	"github.com/pacefriend/client/internal/coaching"
	"github.com/pacefriend/client/internal/telemetry"
)

type spySpeaker struct {
	preloaded atomic.Int32
}

func (s *spySpeaker) Preload(_ context.Context, _ *coaching.Plan) error {
	s.preloaded.Add(1)
	return nil
}
func (s *spySpeaker) Speak(_ coaching.Trigger) {}

func TestUploaderPostsLapAndInstallsCoachingPlan(t *testing.T) {
	var receivedLap atomic.Pointer[telemetry.CompletedLap]

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/telemetry/lap" || r.Method != http.MethodPost {
			http.NotFound(w, r)
			return
		}
		var payload telemetry.CompletedLap
		if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}
		receivedLap.Store(&payload)

		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(LapResponse{
			Status:          "ok",
			LapStored:       true,
			CleanLapsWindow: 5,
			CoachingPlan: &coaching.Plan{
				PlanID:        "plan_spa_v1",
				FocusTurn:     1,
				FocusTurnName: "La Source",
				AvgTimeLossMs: 280,
				Triggers: []coaching.Trigger{
					{ID: "t1_pre", Turn: 1, Phase: coaching.PhasePreBrake, TriggerPct: 0.965, Text: "Frena diez metros más tarde."},
				},
			},
		})
	}))
	defer srv.Close()

	pool := buffer.NewSnapshotPool(16)
	spk := &spySpeaker{}
	ev := coaching.NewEvaluator(spk)

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	uploader := NewUploader(Config{
		BaseURL:  srv.URL,
		APIToken: "test-token",
	}, pool, ev, spk)
	uploader.Start(ctx)

	batch := pool.Acquire(2)
	batch.Samples[0] = telemetry.Sample{TimestampMs: 0, LapDistPct: 0.01, Speed: 60.0, Lap: 2, Gear: 5}
	batch.Samples[1] = telemetry.Sample{TimestampMs: 50, LapDistPct: 0.02, Speed: 61.0, Lap: 2, Gear: 5}

	ok := uploader.Enqueue(telemetry.CompletedLap{
		UserID:       "driver-1",
		TrackSlug:    "spa-up",
		CarName:      "Porsche 911 GT3 R (992)",
		LapNumber:    2,
		LapTimeMs:    138200,
		IsClean:      true,
		SampleRateHz: 20,
	}, batch)
	if !ok {
		t.Fatal("expected Enqueue to succeed")
	}

	uploader.Close()

	got := receivedLap.Load()
	if got == nil || got.LapNumber != 2 || len(got.Samples) != 2 {
		t.Fatalf("unexpected uploaded lap payload: %+v", got)
	}

	plan := ev.ActivePlan()
	if plan == nil || plan.PlanID != "plan_spa_v1" {
		t.Fatalf("expected active coaching plan 'plan_spa_v1', got %+v", plan)
	}
	if spk.preloaded.Load() != 1 {
		t.Fatalf("expected speaker.Preload to be called once, got %d", spk.preloaded.Load())
	}
}
