package engine

import (
	"context"
	"log/slog"
	"time"

	"github.com/pacefriend/client/internal/buffer"
	"github.com/pacefriend/client/internal/coaching"
	"github.com/pacefriend/client/internal/telemetry"
	"github.com/pacefriend/client/internal/transport"
)

// Config parameterizes the 20 Hz telemetry sampling & coaching engine.
type Config struct {
	UserID           string
	SampleRateHz     int
	RingCapacity     int
	MinSamplesPerLap int
	MaxLaps          int // Optional: stop automatically after N completed flying laps (useful in -sim)
	Logger           *slog.Logger
	OnMetadataChange func(telemetry.SessionMetadata)
}

// Runner coordinates the 20 Hz sampling ticker, zero-GC ring buffer, lap-crossing
// detector, spatial trigger evaluator, and non-blocking HTTP uploader.
type Runner struct {
	cfg       Config
	source    telemetry.Source
	ring      *buffer.RingBuffer
	pool      *buffer.SnapshotPool
	evaluator *coaching.Evaluator
	uploader  *transport.Uploader
	logger    *slog.Logger

	initialized       bool
	activeLap         int32
	fullFlyingLap     bool
	lapDirty          bool
	lapStartTimeSec   float64
	lapStartWall      time.Time
	lapStartIncidents int32
	prevIncidents     int32
	prevPct           float32
	completedLaps     int
}

// NewRunner wires all Phase 1 subsystems into a cohesive real-time loop.
func NewRunner(
	cfg Config,
	source telemetry.Source,
	ring *buffer.RingBuffer,
	pool *buffer.SnapshotPool,
	evaluator *coaching.Evaluator,
	uploader *transport.Uploader,
) *Runner {
	if cfg.SampleRateHz <= 0 {
		cfg.SampleRateHz = 20
	}
	if cfg.MinSamplesPerLap <= 0 {
		cfg.MinSamplesPerLap = 20
	}
	if cfg.UserID == "" {
		cfg.UserID = "local-driver"
	}
	if ring == nil {
		ring = buffer.NewRingBuffer(cfg.RingCapacity)
	}
	if pool == nil {
		pool = buffer.NewSnapshotPool(4096)
	}
	return &Runner{
		cfg:       cfg,
		source:    source,
		ring:      ring,
		pool:      pool,
		evaluator: evaluator,
		uploader:  uploader,
		logger:    cfg.Logger,
	}
}

// Run executes the fixed-interval ticker (50 ms at 20 Hz) until ctx is cancelled,
// the source disconnects with an error, or MaxLaps flying laps are completed.
func (r *Runner) Run(ctx context.Context) error {
	interval := time.Second / time.Duration(r.cfg.SampleRateHz)
	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	var frame telemetry.RawFrame
	lastMeta := r.source.SessionMetadata()
	metaCheckTick := 0

	for {
		select {
		case <-ctx.Done():
			return nil
		case now := <-ticker.C:
			ok, err := r.source.ReadFrame(&frame)
			if err != nil {
				return err
			}
			if !ok {
				continue
			}
			metaCheckTick++
			if r.cfg.OnMetadataChange != nil && metaCheckTick >= r.cfg.SampleRateHz {
				metaCheckTick = 0
				if curMeta := r.source.SessionMetadata(); curMeta.TrackName != lastMeta.TrackName || curMeta.CarName != lastMeta.CarName {
					lastMeta = curMeta
					r.cfg.OnMetadataChange(curMeta)
				}
			}
			if stop := r.ProcessFrame(now, &frame); stop {
				return nil
			}
		}
	}
}

// ProcessFrame handles one sampled frame. Exposed for deterministic unit testing
// and zero-allocation benchmarking without wall-clock sleeps.
func (r *Runner) ProcessFrame(now time.Time, frame *telemetry.RawFrame) (stop bool) {
	currLap := frame.Sample.Lap
	currPct := frame.Sample.LapDistPct
	onPitRoad := frame.Sample.Flags&telemetry.FlagOnPitRoad != 0

	if !r.initialized {
		r.initialized = true
		r.activeLap = currLap
		r.fullFlyingLap = currLap >= 1 && currPct < 0.03 && !onPitRoad
		r.lapDirty = onPitRoad || (frame.Sample.Flags&telemetry.FlagOffTrack != 0)
		r.lapStartTimeSec = frame.SessionTimeSec
		r.lapStartWall = now
		r.lapStartIncidents = frame.IncidentCount
		r.prevIncidents = frame.IncidentCount
		r.prevPct = currPct
	} else if currLap != r.activeLap {
		crossedFinishLine := currLap == r.activeLap+1 && r.prevPct > 0.80 && currPct < 0.20 && !onPitRoad

		if crossedFinishLine && r.fullFlyingLap && r.ring.Len() >= r.cfg.MinSamplesPerLap {
			r.emitCompletedLap(now, frame)
			r.completedLaps++
			if r.cfg.MaxLaps > 0 && r.completedLaps >= r.cfg.MaxLaps {
				return true
			}
		} else {
			// Discard partial out-lap or interrupted reset/tow lap cleanly.
			r.ring.Reset()
			if !crossedFinishLine && r.evaluator != nil {
				r.evaluator.ResetCursor()
			}
		}

		r.activeLap = currLap
		r.fullFlyingLap = crossedFinishLine && currLap >= 1
		r.lapDirty = onPitRoad || (frame.Sample.Flags&telemetry.FlagOffTrack != 0)
		r.lapStartTimeSec = frame.SessionTimeSec
		r.lapStartWall = now
		r.lapStartIncidents = frame.IncidentCount
	}

	if frame.IncidentCount > r.prevIncidents {
		frame.Sample.Flags |= telemetry.FlagIncident
		r.lapDirty = true
	}
	r.prevIncidents = frame.IncidentCount

	if frame.Sample.Flags&(telemetry.FlagOffTrack|telemetry.FlagOnPitRoad) != 0 {
		r.lapDirty = true
	}

	if r.fullFlyingLap {
		frame.Sample.TimestampMs = r.computeRelativeMs(now, frame.SessionTimeSec)
		r.ring.Push(frame.Sample)
	}

	if r.evaluator != nil {
		r.evaluator.Step(currLap, currPct, onPitRoad)
	}

	r.prevPct = currPct
	return false
}

func (r *Runner) emitCompletedLap(now time.Time, crossingFrame *telemetry.RawFrame) {
	batch := r.ring.SnapshotAndReset(r.pool)
	if batch == nil {
		return
	}

	var lapTimeMs uint32
	switch {
	case crossingFrame.LapLastLapTimeSec > 5.0:
		lapTimeMs = uint32(crossingFrame.LapLastLapTimeSec * 1000.0)
	case crossingFrame.SessionTimeSec > r.lapStartTimeSec:
		lapTimeMs = uint32((crossingFrame.SessionTimeSec - r.lapStartTimeSec) * 1000.0)
	default:
		lapTimeMs = uint32(now.Sub(r.lapStartWall).Milliseconds())
	}

	incDelta := crossingFrame.IncidentCount - r.lapStartIncidents
	if incDelta < 0 {
		incDelta = 0
	}
	isClean := !r.lapDirty && incDelta == 0

	meta := r.source.SessionMetadata()
	completed := telemetry.CompletedLap{
		UserID:        r.cfg.UserID,
		TrackSlug:     meta.TrackSlug,
		TrackName:     meta.TrackName,
		TrackLengthKm: meta.TrackLengthKm,
		CarName:       meta.CarName,
		SessionID:     meta.SessionID,
		SubSessionID:  meta.SubSessionID,
		LapNumber:     r.activeLap,
		LapTimeMs:     lapTimeMs,
		IsClean:       isClean,
		IncidentDelta: incDelta,
		TrackTempC:    crossingFrame.TrackTempC,
		AirTempC:      crossingFrame.AirTempC,
		IsWet:         crossingFrame.IsWet,
		SampleRateHz:  r.cfg.SampleRateHz,
	}

	if r.logger != nil {
		r.logger.Info("flying lap completed",
			slog.Int("lap", int(completed.LapNumber)),
			slog.Uint64("lap_time_ms", uint64(completed.LapTimeMs)),
			slog.Bool("is_clean", completed.IsClean),
			slog.String("track", completed.TrackName),
			slog.String("car", completed.CarName),
			slog.Int("samples", len(batch.Samples)),
		)
	}

	if r.uploader != nil {
		r.uploader.Enqueue(completed, batch)
	} else {
		r.pool.Release(batch)
	}
}

func (r *Runner) computeRelativeMs(now time.Time, sessionTimeSec float64) uint32 {
	if sessionTimeSec >= r.lapStartTimeSec && sessionTimeSec > 0 {
		return uint32((sessionTimeSec - r.lapStartTimeSec) * 1000.0)
	}
	ms := now.Sub(r.lapStartWall).Milliseconds()
	if ms < 0 {
		return 0
	}
	return uint32(ms)
}
