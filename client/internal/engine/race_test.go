package engine_test

import (
	"bytes"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/pacefriend/client/internal/buffer"
	"github.com/pacefriend/client/internal/coaching"
	"github.com/pacefriend/client/internal/config"
	"github.com/pacefriend/client/internal/engine"
	"github.com/pacefriend/client/internal/irsdk"
	"github.com/pacefriend/client/internal/ptt"
	"github.com/pacefriend/client/internal/telemetry"
	"github.com/pacefriend/client/internal/transport"
)

type raceMockSource struct {
	meta telemetry.SessionMetadata
}

func (r *raceMockSource) Connect(_ context.Context) error             { return nil }
func (r *raceMockSource) Close() error                                { return nil }
func (r *raceMockSource) ReadFrame(_ *telemetry.RawFrame) (bool, error) { return false, nil }
func (r *raceMockSource) SessionMetadata() telemetry.SessionMetadata  { return r.meta }

func TestRaceCombatSilenceHazardRadarAndPitCommands(t *testing.T) {
	_ = irsdk.RecordedPitCommands(true)

	var hazardMu sync.Mutex
	var hazards []transport.HazardAlertRequest

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/v1/telemetry/hazard":
			var hz transport.HazardAlertRequest
			_ = json.NewDecoder(r.Body).Decode(&hz)
			hazardMu.Lock()
			hazards = append(hazards, hz)
			hazardMu.Unlock()
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"status":"ok"}`))
		case "/api/v1/telemetry/briefing":
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"status":"ok","pit_commands":[{"action":"clear_tires"},{"action":"fuel","value":19}]}`))
		default:
			w.Header().Set("Content-Type", "application/json")
			_, _ = w.Write([]byte(`{"status":"ok"}`))
		}
	}))
	defer srv.Close()

	spk := &recordSpeaker{}
	eval := coaching.NewEvaluator(spk)
	eval.SetPlan(&coaching.Plan{
		PlanID:    "race_clean_air_plan",
		FocusTurn: 1,
		Triggers: []coaching.Trigger{
			{ID: "t1_pre", Turn: 1, Phase: coaching.PhasePreBrake, TriggerPct: 0.25, Text: "Abre entrada"},
		},
	})

	pool := buffer.NewSnapshotPool(512)
	ring := buffer.NewRingBuffer(512)
	up := transport.NewUploader(transport.Config{BaseURL: srv.URL, APIToken: "tok"}, pool, eval, spk)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	up.Start(ctx)
	defer up.Close()

	var meta telemetry.SessionMetadata
	meta.TrackSlug = "spa-up"
	meta.TrackName = "Circuit de Spa-Francorchamps"
	meta.TrackLengthKm = 7.004
	meta.CarName = "Porsche 911 GT3 R (992)"
	meta.DriverCarIdx = 0
	meta.IncidentLimit = 17
	meta.SessionTypes[0] = "race"

	src := &raceMockSource{meta: meta}
	runner := engine.NewRunner(engine.Config{SampleRateHz: 20}, src, ring, pool, eval, up)

	// Lap 1 in wheel-to-wheel COMBAT (P1 is 0.6s ahead!) + Car #2 spun OffTrack 220m ahead -> trigger MUST be silenced & hazard sent!
	f1 := telemetry.RawFrame{
		SessionTimeSec:      30.0,
		IsOnTrack:           true,
		PlayerCarIdx:        0,
		PlayerClassPosition: 2,
		HasGridArrays:       true,
		Sample:              telemetry.Sample{Lap: 1, LapDistPct: 0.20, Speed: 55.0},
	}
	f1.CarIdxClassPosition[0] = 2
	f1.CarIdxLapDistPct[0] = 0.20
	f1.CarIdxEstTime[0] = 28.0
	f1.CarIdxTrackSurface[0] = 3

	// Rival P1 right ahead (0.6s gap -> COMBAT!)
	f1.CarIdxClassPosition[1] = 1
	f1.CarIdxLapDistPct[1] = 0.205
	f1.CarIdxEstTime[1] = 28.6
	f1.CarIdxTrackSurface[1] = 3

	// Spun car #2 OffTrack (surface = 0) at pct 0.232 (~224m ahead on 7004m Spa!)
	f1.CarIdxClassPosition[2] = 5
	f1.CarIdxLapDistPct[2] = 0.232
	f1.CarIdxTrackSurface[2] = 0

	runner.ProcessFrame(time.Now(), &f1)
	f1.Sample.LapDistPct = 0.26 // Crosses trigger at 0.25 while in combat!
	f1.SessionTimeSec = 30.5
	runner.ProcessFrame(time.Now(), &f1)

	if len(spk.spokenIDs()) != 0 {
		t.Fatalf("expected 0 technical triggers during close race combat, got %v", spk.spokenIDs())
	}

	// Lap 2 in CLEAN AIR (rival now 3.8s ahead) -> technical trigger MUST fire!
	f2 := f1
	f2.Sample.Lap = 2
	f2.Sample.LapDistPct = 0.20
	f2.SessionTimeSec = 170.0
	f2.CarIdxLapDistPct[1] = 0.24
	f2.CarIdxEstTime[1] = 31.8 // 3.8s ahead -> clean air!
	f2.CarIdxTrackSurface[2] = 3

	runner.ProcessFrame(time.Now(), &f2)
	f2.Sample.LapDistPct = 0.26
	f2.SessionTimeSec = 170.5
	runner.ProcessFrame(time.Now(), &f2)

	if len(spk.spokenIDs()) != 1 || spk.spokenIDs()[0] != "t1_pre" {
		t.Fatalf("expected clean-air trigger t1_pre to fire once, got %v", spk.spokenIDs())
	}

	// Wait briefly for async briefing pit commands & hazard alert
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		hazardMu.Lock()
		hCount := len(hazards)
		hazardMu.Unlock()
		if hCount >= 1 && len(irsdk.RecordedPitCommands(false)) >= 2 {
			break
		}
		time.Sleep(15 * time.Millisecond)
	}

	hazardMu.Lock()
	defer hazardMu.Unlock()
	if len(hazards) != 1 || hazards[0].AheadMeters < 150 {
		t.Fatalf("expected 1 ahead hazard alert (~224m), got %+v", hazards)
	}

	cmds := irsdk.RecordedPitCommands(true)
	if len(cmds) != 2 || cmds[0].Action != "clear_tires" || cmds[1].Action != "fuel" || cmds[1].Value != 19 {
		t.Fatalf("expected recorded pit commands [clear_tires, fuel:19], got %+v", cmds)
	}
}

func TestLocalConfigVolumeAndPTTWAVEncoding(t *testing.T) {
	tmpDir := t.TempDir()
	origWD, _ := os.Getwd()
	if err := os.Chdir(tmpDir); err != nil {
		t.Fatal(err)
	}
	defer func() { _ = os.Chdir(origWD) }()

	_ = os.WriteFile(filepath.Join(tmpDir, "pacefriend.json"), []byte(`{"api_url":"https://example.com","api_token":"abc"}`), 0o600)

	if got := config.LoadAdjacent().EffectiveVolume(); got != 85 {
		t.Fatalf("expected default volume 85, got %d", got)
	}

	err := config.SaveAdjacent(func(fc *config.FileConfig) {
		v := 40
		fc.Volume = &v
		fc.PTT = config.PTTConfig{DeviceType: "joystick", JoystickID: 1, Button: 4, Label: "Volante #2 · Botón 5"}
	})
	if err != nil {
		t.Fatalf("SaveAdjacent failed: %v", err)
	}

	reloaded := config.LoadAdjacent()
	if reloaded.EffectiveVolume() != 40 {
		t.Fatalf("expected persisted volume 40, got %d", reloaded.EffectiveVolume())
	}
	if reloaded.EffectivePTT().Label != "Volante #2 · Botón 5" {
		t.Fatalf("unexpected persisted PTT label: %s", reloaded.EffectivePTT().Label)
	}

	wav := ptt.SynthesizeToneWAV(1000, 100, 0.5)
	if !bytes.HasPrefix(wav, []byte("RIFF")) || len(wav) <= 44 {
		t.Fatalf("expected valid RIFF WAV buffer, len=%d", len(wav))
	}
}

type recordSpeaker struct {
	mu  sync.Mutex
	ids []string
}

func (r *recordSpeaker) Preload(_ context.Context, _ *coaching.Plan) error { return nil }
func (r *recordSpeaker) Speak(t coaching.Trigger) {
	r.mu.Lock()
	r.ids = append(r.ids, t.ID)
	r.mu.Unlock()
}
func (r *recordSpeaker) spokenIDs() []string {
	r.mu.Lock()
	defer r.mu.Unlock()
	out := make([]string, len(r.ids))
	copy(out, r.ids)
	return out
}
