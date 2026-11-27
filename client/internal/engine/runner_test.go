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
	var uploadedSectors []telemetry.CompletedSector
	var sessionEndCalled bool

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v1/telemetry/session/end" {
			mu.Lock()
			sessionEndCalled = true
			mu.Unlock()
			w.WriteHeader(http.StatusOK)
			return
		}
		if r.URL.Path == "/api/v1/telemetry/sector" {
			var sec telemetry.CompletedSector
			if err := json.NewDecoder(r.Body).Decode(&sec); err != nil {
				http.Error(w, err.Error(), http.StatusBadRequest)
				return
			}
			mu.Lock()
			uploadedSectors = append(uploadedSectors, sec)
			mu.Unlock()
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"status":"ok","sector_stored":true}`))
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
			SectorSplits:  []float32{0.0, 0.341, 0.685},
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
		simSec += 1.0
		runner.ProcessFrame(baseTime, &telemetry.RawFrame{
			SessionTimeSec: simSec,
			Sample:         telemetry.Sample{Lap: 1, LapDistPct: pct, Speed: 70.0, Throttle: 1.0, Gear: 6},
		})
	}

	// 3. Full dirty flying Lap 2 (has OffTrack excursion mid-lap in S2 at pct=0.40)
	for i := 0; i < 50; i++ {
		pct := float32(i) * 0.02
		simSec += 1.0
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
	// Lap 2 S1 (index 2) was clean, while Lap 2 S2 (index 3) had OffTrack at pct=0.40!
	if len(uploadedSectors) != 4 || !uploadedSectors[2].IsClean || uploadedSectors[3].IsClean {
		t.Fatalf("expected 4 intermediate sectors with Lap 2 S1=clean & S2=dirty, got %+v", uploadedSectors)
	}
}

func TestRunnerSendsBriefingFuelMetricsAndPitTyreDebrief(t *testing.T) {
	var mu sync.Mutex
	var briefings []transport.BriefingRequest
	var pitStops []transport.PitStopRequest
	var sectors []telemetry.CompletedSector
	var laps []telemetry.CompletedLap

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/api/v1/telemetry/briefing":
			var b transport.BriefingRequest
			_ = json.NewDecoder(r.Body).Decode(&b)
			mu.Lock()
			briefings = append(briefings, b)
			mu.Unlock()
			_, _ = w.Write([]byte(`{"status":"ok","audio_settings":{"volume":75}}`))
		case "/api/v1/telemetry/sector":
			var sec telemetry.CompletedSector
			_ = json.NewDecoder(r.Body).Decode(&sec)
			mu.Lock()
			sectors = append(sectors, sec)
			mu.Unlock()
			_, _ = w.Write([]byte(`{"status":"ok","sector_stored":true}`))
		case "/api/v1/telemetry/pit-stop":
			var p transport.PitStopRequest
			_ = json.NewDecoder(r.Body).Decode(&p)
			mu.Lock()
			pitStops = append(pitStops, p)
			mu.Unlock()
			_, _ = w.Write([]byte(`{"status":"ok"}`))
		case "/api/v1/telemetry/lap":
			var l telemetry.CompletedLap
			_ = json.NewDecoder(r.Body).Decode(&l)
			mu.Lock()
			laps = append(laps, l)
			mu.Unlock()
			_, _ = w.Write([]byte(`{"status":"ok","lap_stored":true}`))
		default:
			_, _ = w.Write([]byte(`{"status":"ok"}`))
		}
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
			SectorSplits:  []float32{0.0, 0.341, 0.685},
		},
	}

	runner := NewRunner(Config{
		UserID:           "driver-vicent",
		SampleRateHz:     20,
		MinSamplesPerLap: 10,
	}, src, ring, pool, ev, uploader)

	now := time.Now()
	// 1. Out-lap leaving pits onto track with 50L fuel -> triggers session_start briefing
	runner.ProcessFrame(now, &telemetry.RawFrame{
		IsOnTrack:      true,
		SessionTimeSec: 8.0,
		TrackTempC:     24.0,
		AirTempC:       18.5,
		FuelLevelL:     50.0,
		Sample:         telemetry.Sample{Lap: 0, LapDistPct: 0.95, Speed: 45.0, Gear: 3},
	})

	// 2. Clean Lap 1 burning 2.8L (50.0 -> 47.2) across S1 (0..0.341), S2 (0.341..0.685), S3
	for i := 0; i < 20; i++ {
		pct := float32(i) * 0.05
		runner.ProcessFrame(now, &telemetry.RawFrame{
			IsOnTrack:      true,
			SessionTimeSec: 10.0 + float64(i)*2.0,
			TrackTempC:     24.0,
			FuelLevelL:     50.0 - float32(i)*0.14,
			Sample:         telemetry.Sample{Lap: 1, LapDistPct: pct, Speed: 68.0, Gear: 5},
		})
	}
	runner.ProcessFrame(now, &telemetry.RawFrame{
		IsOnTrack:         true,
		SessionTimeSec:    148.0,
		LapLastLapTimeSec: 138.0,
		TrackTempC:        24.0,
		FuelLevelL:        47.2,
		Sample:            telemetry.Sample{Lap: 2, LapDistPct: 0.01, Speed: 70.0, Gear: 6},
	})

	// 3. Driver exits to garage -> iRacing unlocks real tyre temps
	runner.ProcessFrame(now, &telemetry.RawFrame{
		IsOnTrack:   false,
		TyreLFTempC: 98.5,
		TyreRFTempC: 89.0,
		TyreLRTempC: 86.0,
		TyreRRTempC: 85.0,
		Sample:      telemetry.Sample{Lap: 2, LapDistPct: 0.10, Speed: 0.0},
	})

	// Give async event goroutine a brief moment before closing
	time.Sleep(50 * time.Millisecond)
	uploader.Close()

	mu.Lock()
	defer mu.Unlock()

	if len(briefings) != 1 || briefings[0].Reason != "session_start" || briefings[0].FuelLevelL != 50.0 {
		t.Fatalf("expected 1 session_start briefing with 50L fuel, got %+v", briefings)
	}
	if len(laps) != 1 || laps[0].FuelLevelL != 47.2 || laps[0].FuelUsedL < 2.7 {
		t.Fatalf("expected Lap 1 fuel metrics, got %+v", laps)
	}
	if len(sectors) != 2 || sectors[0].SectorNumber != 1 || sectors[1].SectorNumber != 2 || !sectors[0].IsClean {
		t.Fatalf("expected 2 intermediate sectors (S1 & S2) uploaded mid-lap, got %+v", sectors)
	}
	if len(pitStops) != 1 || pitStops[0].LFTempC != 98.5 {
		t.Fatalf("expected 1 pit/garage tyre debrief with LF=98.5, got %+v", pitStops)
	}
}
