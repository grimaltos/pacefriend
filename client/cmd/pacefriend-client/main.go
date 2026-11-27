package main

import (
	"context"
	"errors"
	"flag"
	"io"
	"log/slog"
	"os"
	"os/signal"
	"path/filepath"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/pacefriend/client/internal/audio"
	"github.com/pacefriend/client/internal/buffer"
	"github.com/pacefriend/client/internal/coaching"
	"github.com/pacefriend/client/internal/config"
	"github.com/pacefriend/client/internal/engine"
	"github.com/pacefriend/client/internal/irsdk"
	"github.com/pacefriend/client/internal/telemetry"
	"github.com/pacefriend/client/internal/transport"
	"github.com/pacefriend/client/internal/tray"
)

type heartbeatState struct {
	state     string
	trackName string
}

func main() {
	fileCfg := config.LoadAdjacent()

	var (
		apiURL     = flag.String("api-url", firstNonEmpty(os.Getenv("PACEFRIEND_API_URL"), fileCfg.APIURL, ""), "Base URL of PaceFriend Laravel API")
		apiToken   = flag.String("api-token", firstNonEmpty(os.Getenv("PACEFRIEND_API_TOKEN"), fileCfg.APIToken, ""), "Bearer token for API authentication")
		userID     = flag.String("user-id", firstNonEmpty(os.Getenv("PACEFRIEND_USER_ID"), fileCfg.UserID, "driver-local"), "Driver identifier")
		shmName    = flag.String("shm-name", firstNonEmpty(os.Getenv("PACEFRIEND_SHM_NAME"), fileCfg.ShmName, irsdk.OfficialShmName), `Windows shared memory block name`)
		rateHz     = flag.Int("hz", 20, "Telemetry sampling frequency in Hz")
		simMode    = flag.Bool("sim", false, "Use built-in synthetic iRacing memory simulator (for Linux/offline testing)")
		simSpeedup = flag.Float64("sim-speedup", 10.0, "Time multiplier when -sim is enabled")
		simLaps    = flag.Int("sim-laps", 0, "Stop automatically after N completed flying laps (0 = run indefinitely)")
		demoPlan   = flag.Bool("demo-triggers", true, "Preload a sample Turn 1 coaching plan in -sim mode when no API URL is set")
		muteAudio  = flag.Bool("mute", fileCfg.Mute, "Silence local audio playback")
		noTray     = flag.Bool("no-tray", false, "Disable Windows System Tray icon")
	)
	flag.Parse()

	logWriter, logFile := openDiagnosticLog(*simMode)
	if logFile != nil {
		defer logFile.Close()
	}

	logger := slog.New(slog.NewTextHandler(logWriter, &slog.HandlerOptions{
		Level: slog.LevelInfo,
	}))

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	pool := buffer.NewSnapshotPool(4096)
	ring := buffer.NewRingBuffer(buffer.DefaultCapacity)
	speaker := audio.NewEngine(audio.Config{
		Muted:    *muteAudio,
		APIToken: *apiToken,
		Logger:   logger,
	})
	defer speaker.Close()
	evaluator := coaching.NewEvaluator(speaker)

	initialTrayStatus := "Esperando sesión de iRacing..."
	if !*simMode && (*apiURL == "" || *apiToken == "") {
		initialTrayStatus = "Sin pacefriend.json (extrae todo el ZIP)"
	}

	uploader := transport.NewUploader(transport.Config{
		BaseURL:  *apiURL,
		APIToken: *apiToken,
		Logger:   logger,
	}, pool, evaluator, speaker)
	uploader.Start(ctx)
	defer uploader.Close()

	var trayMgr *tray.Manager
	if !*noTray && !*simMode {
		var testRadioCb func()
		if *apiURL != "" && *apiToken != "" {
			testRadioCb = uploader.TriggerRadioTest
		}
		trayMgr = tray.Start(tray.Options{
			InitialStatus: initialTrayStatus,
			PanelURL:      *apiURL,
			IsMuted:       speaker.IsMuted,
			OnToggleMute: func() {
				speaker.SetMuted(!speaker.IsMuted())
			},
			OnTestRadio: testRadioCb,
			OnExit:      stop,
		})
		defer trayMgr.Close()
	}

	if *simMode && *demoPlan && *apiURL == "" {
		evaluator.SetPlan(&coaching.Plan{
			PlanID:        "demo_spa_t1",
			FocusTurn:     1,
			FocusTurnName: "La Source",
			AvgTimeLossMs: 310,
			Triggers: []coaching.Trigger{
				{
					ID:         "t1_pre_brake",
					Turn:       1,
					Phase:      coaching.PhasePreBrake,
					TriggerPct: 0.965,
					Text:       "En La Source aguanta la referencia y retrasa diez metros la frenada.",
				},
				{
					ID:         "t1_post_exit",
					Turn:       1,
					Phase:      coaching.PhasePostExit,
					TriggerPct: 0.032,
					Text:       "Suelta freno antes hacia el vértice para traccionar limpio en bajada.",
				},
			},
		})
	}

	var hbState atomic.Value
	hbState.Store(heartbeatState{state: "waiting_iracing", trackName: ""})

	// Immediate & periodic heartbeat so the web panel shows "Cliente Conectado" right away
	if *apiURL != "" && *apiToken != "" {
		go runHeartbeatLoop(ctx, uploader, logger, trayMgr, &hbState)
	} else if !*simMode {
		logger.Warn("missing api_url or api_token; ensure pacefriend.json is in the same folder as pacefriend-client.exe")
	}

	var source telemetry.Source
	if *simMode {
		logger.Info("starting PaceFriend client in simulator mode",
			slog.Float64("speedup", *simSpeedup),
			slog.Int("hz", *rateHz),
			slog.Int("max_laps", *simLaps),
		)
		source = irsdk.NewSimulatorSource(irsdk.SimulatorConfig{
			Speedup:       *simSpeedup,
			DirtyEveryLap: 4,
		})
	} else {
		logger.Info("starting PaceFriend client (native Windows shared memory)",
			slog.String("shm_name", *shmName),
			slog.String("api_url", *apiURL),
			slog.Int("hz", *rateHz),
		)
		source = irsdk.NewSharedMemorySource(*shmName)
	}
	defer source.Close()

	runLoop(ctx, logger, source, ring, pool, evaluator, uploader, trayMgr, &hbState, engine.Config{
		UserID:       *userID,
		SampleRateHz: *rateHz,
		MaxLaps:      *simLaps,
		Logger:       logger,
	}, *simMode, *apiURL != "" && *apiToken != "")
}

func runHeartbeatLoop(
	ctx context.Context,
	uploader *transport.Uploader,
	logger *slog.Logger,
	trayMgr *tray.Manager,
	hbState *atomic.Value,
) {
	sendOnce := func() {
		st, _ := hbState.Load().(heartbeatState)
		if err := uploader.SendHeartbeat(ctx, st.state, st.trackName); err != nil {
			logger.Warn("heartbeat failed", slog.Any("err", err))
			if st.state == "waiting_iracing" && trayMgr != nil {
				trayMgr.SetStatus("Error API (" + err.Error() + ")")
			}
		}
	}

	sendOnce()
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			sendOnce()
		}
	}
}

func runLoop(
	ctx context.Context,
	logger *slog.Logger,
	source telemetry.Source,
	ring *buffer.RingBuffer,
	pool *buffer.SnapshotPool,
	evaluator *coaching.Evaluator,
	uploader *transport.Uploader,
	trayMgr *tray.Manager,
	hbState *atomic.Value,
	cfg engine.Config,
	simMode bool,
	hasConfig bool,
) {
	loggedWait := false

	for ctx.Err() == nil {
		if err := source.Connect(ctx); err != nil {
			if errors.Is(err, irsdk.ErrUnsupportedOS) {
				logger.Error("cannot attach native Windows shared memory on this OS", slog.Any("err", err))
				return
			}
			if !loggedWait {
				logger.Info("waiting for iRacing 3D simulator session in shared memory...", slog.Any("detail", err))
				if hasConfig {
					trayMgr.SetStatus("Conectado · Esperando pista iRacing...")
				}
				hbState.Store(heartbeatState{state: "waiting_iracing", trackName: ""})
				loggedWait = true
			}
			select {
			case <-ctx.Done():
				return
			case <-time.After(2 * time.Second):
				continue
			}
		}

		loggedWait = false
		meta := source.SessionMetadata()
		logger.Info("connected to iRacing telemetry memory",
			slog.String("track", meta.TrackName),
			slog.String("car", meta.CarName),
		)

		if meta.TrackName != "" {
			trayMgr.SetStatus("En pista: " + meta.TrackName)
		} else {
			trayMgr.SetStatus("iRacing detectado (cargando sesión)")
		}
		hbState.Store(heartbeatState{state: "in_session", trackName: meta.TrackName})
		if hasConfig {
			_ = uploader.SendHeartbeat(ctx, "in_session", meta.TrackName)
		}

		ring.Reset()
		evaluator.ResetCursor()

		sessionCfg := cfg
		sessionCfg.OnMetadataChange = func(updated telemetry.SessionMetadata) {
			logger.Info("session metadata updated",
				slog.String("track", updated.TrackName),
				slog.String("car", updated.CarName),
			)
			if updated.TrackName != "" {
				trayMgr.SetStatus("En pista: " + updated.TrackName)
			}
			hbState.Store(heartbeatState{state: "in_session", trackName: updated.TrackName})
			if hasConfig {
				go func(track string) {
					_ = uploader.SendHeartbeat(ctx, "in_session", track)
				}(updated.TrackName)
			}
		}

		runner := engine.NewRunner(sessionCfg, source, ring, pool, evaluator, uploader)

		err := runner.Run(ctx)
		_ = source.Close()
		evaluator.SetPlan(nil)

		if err == nil || simMode {
			if err != nil {
				logger.Error("simulator exited with error", slog.Any("err", err))
			}
			return
		}

		logger.Info("iRacing 3D session ended or disconnected, waiting for next session", slog.Any("reason", err))
		hbState.Store(heartbeatState{state: "waiting_iracing", trackName: ""})
		if hasConfig {
			uploader.NotifySessionEnd()
			_ = uploader.SendHeartbeat(ctx, "waiting_iracing", "")
			trayMgr.SetStatus("Conectado · Esperando pista iRacing...")
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(2 * time.Second):
		}
	}
}

// guiSafeLogWriter writes to the diagnostic file unconditionally first, and mirrors
// to os.Stdout on a best-effort basis so -H=windowsgui invalid stdout handles never
// abort file logging.
type guiSafeLogWriter struct {
	file *os.File
}

func (w *guiSafeLogWriter) Write(p []byte) (int, error) {
	n, err := w.file.Write(p)
	_, _ = os.Stdout.Write(p)
	return n, err
}

func openDiagnosticLog(simMode bool) (io.Writer, *os.File) {
	if simMode {
		return os.Stdout, nil
	}
	logPath := "pacefriend.log"
	if exe, err := os.Executable(); err == nil {
		logPath = filepath.Join(filepath.Dir(exe), "pacefriend.log")
	}
	f, err := os.OpenFile(logPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
	if err != nil {
		return io.Discard, nil
	}
	return &guiSafeLogWriter{file: f}, f
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}
