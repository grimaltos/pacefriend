package main

import (
	"context"
	"errors"
	"flag"
	"log/slog"
	"os"
	"os/signal"
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

func main() {
	fileCfg := config.LoadAdjacent()

	var (
		apiURL     = flag.String("api-url", firstNonEmpty(os.Getenv("PACEFRIEND_API_URL"), fileCfg.APIURL, ""), "Base URL of PaceFriend Laravel API")
		apiToken   = flag.String("api-token", firstNonEmpty(os.Getenv("PACEFRIEND_API_TOKEN"), fileCfg.APIToken, ""), "Bearer token for API authentication")
		userID     = flag.String("user-id", firstNonEmpty(os.Getenv("PACEFRIEND_USER_ID"), fileCfg.UserID, "driver-local"), "Driver identifier")
		shmName    = flag.String("shm-name", firstNonEmpty(os.Getenv("PACEFRIEND_SHM_NAME"), fileCfg.ShmName, irsdk.SpecShmName), `Windows shared memory block name`)
		rateHz     = flag.Int("hz", 20, "Telemetry sampling frequency in Hz")
		simMode    = flag.Bool("sim", false, "Use built-in synthetic iRacing memory simulator (for Linux/offline testing)")
		simSpeedup = flag.Float64("sim-speedup", 10.0, "Time multiplier when -sim is enabled")
		simLaps    = flag.Int("sim-laps", 0, "Stop automatically after N completed flying laps (0 = run indefinitely)")
		demoPlan   = flag.Bool("demo-triggers", true, "Preload a sample Turn 1 coaching plan in -sim mode when no API URL is set")
		muteAudio  = flag.Bool("mute", fileCfg.Mute, "Silence local audio playback")
		noTray     = flag.Bool("no-tray", false, "Disable Windows System Tray icon")
	)
	flag.Parse()

	logger := slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{
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

	var trayMgr *tray.Manager
	if !*noTray && !*simMode {
		trayMgr = tray.Start(tray.Options{
			InitialStatus: "Esperando sesión de iRacing...",
			PanelURL:      *apiURL,
			IsMuted:       speaker.IsMuted,
			OnToggleMute: func() {
				speaker.SetMuted(!speaker.IsMuted())
			},
			OnExit: stop,
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

	uploader := transport.NewUploader(transport.Config{
		BaseURL:  *apiURL,
		APIToken: *apiToken,
		Logger:   logger,
	}, pool, evaluator, speaker)
	uploader.Start(ctx)
	defer uploader.Close()

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
			slog.Int("hz", *rateHz),
		)
		source = irsdk.NewSharedMemorySource(*shmName)
	}
	defer source.Close()

	runLoop(ctx, logger, source, ring, pool, evaluator, uploader, trayMgr, engine.Config{
		UserID:       *userID,
		SampleRateHz: *rateHz,
		MaxLaps:      *simLaps,
		Logger:       logger,
	}, *simMode)
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
	cfg engine.Config,
	simMode bool,
) {
	loggedWait := false

	for ctx.Err() == nil {
		if err := source.Connect(ctx); err != nil {
			if errors.Is(err, irsdk.ErrUnsupportedOS) {
				logger.Error("cannot attach native Windows shared memory on this OS", slog.Any("err", err))
				return
			}
			if !loggedWait {
				logger.Info("waiting for iRacing session in shared memory...", slog.Any("detail", err))
				trayMgr.SetStatus("Esperando sesión de iRacing...")
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
		logger.Info("connected to telemetry memory",
			slog.String("track", meta.TrackName),
			slog.String("car", meta.CarName),
		)
		if meta.TrackName != "" {
			trayMgr.SetStatus("En pista: " + meta.TrackName)
		} else {
			trayMgr.SetStatus("Conectado a iRacing")
		}

		ring.Reset()
		evaluator.ResetCursor()
		runner := engine.NewRunner(cfg, source, ring, pool, evaluator, uploader)

		err := runner.Run(ctx)
		_ = source.Close()

		if err == nil || simMode {
			if err != nil {
				logger.Error("simulator exited with error", slog.Any("err", err))
			}
			return
		}

		logger.Warn("telemetry stream interrupted, will retry connection", slog.Any("err", err))
		trayMgr.SetStatus("Esperando sesión de iRacing...")
		select {
		case <-ctx.Done():
			return
		case <-time.After(2 * time.Second):
		}
	}
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}
