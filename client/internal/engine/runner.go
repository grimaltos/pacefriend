package engine

import (
	"context"
	"log/slog"
	"math"
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
	lapStartFuelL     float32
	prevIncidents     int32
	prevPct           float32
	completedLaps     int

	// Engineer radio briefing & pit tyre pyrometer state
	briefingSent          bool
	briefedTrackTempC     float32
	briefedIsWet          bool
	droveOnTrackThisStint bool
	pitDebriefSent        bool
	pitStoppedTicks       int
	stintLaps             int

	// Official iRacing sector (partial) tracking
	secNum         int
	secStartIdx    int
	secStartTime   float64
	secCleanEntry  bool
	secHadDirty    bool
	lastDirtyCum   float64
	dirtyTracked   bool
}

// NewRunner wires all subsystems into a cohesive real-time loop.
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

	r.evaluateBriefingAndPitTyres(frame, onPitRoad)

	if !r.initialized {
		r.initialized = true
		r.activeLap = currLap
		r.fullFlyingLap = currLap >= 1 && currPct < 0.03 && !onPitRoad
		r.lapDirty = onPitRoad || (frame.Sample.Flags&telemetry.FlagOffTrack != 0)
		r.lapStartTimeSec = frame.SessionTimeSec
		r.lapStartWall = now
		r.lapStartIncidents = frame.IncidentCount
		r.lapStartFuelL = frame.FuelLevelL
		r.prevIncidents = frame.IncidentCount
		r.prevPct = currPct
		r.secNum = 0
	} else if currLap != r.activeLap {
		crossedFinishLine := currLap == r.activeLap+1 && r.prevPct > 0.80 && currPct < 0.20 && !onPitRoad
		willEmitLap := crossedFinishLine && r.fullFlyingLap && r.ring.Len() >= r.cfg.MinSamplesPerLap

		// Out-laps / non-flying laps: still salvage the final partial before the ring resets.
		if crossedFinishLine && !willEmitLap {
			splits := r.source.SessionMetadata().SectorSplits
			if len(splits) >= 2 && r.secNum == len(splits) {
				r.emitSector(frame, r.activeLap, r.secNum, splits[r.secNum-1], 1.0)
			}
		}

		if willEmitLap {
			r.emitCompletedLap(now, frame)
			r.completedLaps++
			r.stintLaps++
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
		r.lapStartFuelL = frame.FuelLevelL

		if crossedFinishLine {
			r.startSector(1, frame, float64(currLap))
		} else {
			r.secNum = 0
		}
	} else {
		r.checkIntermediateSectors(frame, currLap, currPct)
	}

	if frame.IncidentCount > r.prevIncidents {
		frame.Sample.Flags |= telemetry.FlagIncident
		r.lapDirty = true
	}
	r.prevIncidents = frame.IncidentCount

	if frame.Sample.Flags&(telemetry.FlagOffTrack|telemetry.FlagOnPitRoad|telemetry.FlagIncident) != 0 {
		r.lapDirty = true
		r.secHadDirty = true
		r.lastDirtyCum = float64(currLap) + float64(currPct)
		r.dirtyTracked = true
	}

	// Record every on-track sample: flying laps are uploaded whole, while out-laps and
	// interrupted laps still contribute their clean intermediate partials.
	frame.Sample.TimestampMs = r.computeRelativeMs(now, frame.SessionTimeSec)
	r.ring.Push(frame.Sample)

	if r.evaluator != nil {
		r.evaluator.Step(currLap, currPct, onPitRoad)
	}

	r.prevPct = currPct
	return false
}

func (r *Runner) evaluateBriefingAndPitTyres(frame *telemetry.RawFrame, onPitRoad bool) {
	if r.uploader == nil {
		return
	}

	// 1. Out-lap / Session Start Greeting & Weather-Shift Alerts
	if frame.IsOnTrack && !onPitRoad {
		if !r.briefingSent {
			meta := r.source.SessionMetadata()
			if meta.TrackSlug != "" && meta.CarName != "" {
				r.briefingSent = true
				r.briefedTrackTempC = frame.TrackTempC
				r.briefedIsWet = frame.IsWet
				r.uploader.EnqueueBriefing(transport.BriefingRequest{
					TrackSlug:     meta.TrackSlug,
					TrackName:     meta.TrackName,
					TrackLengthKm: meta.TrackLengthKm,
					CarName:       meta.CarName,
					SessionID:     meta.SessionID,
					SubSessionID:  meta.SubSessionID,
					TrackTempC:    frame.TrackTempC,
					AirTempC:      frame.AirTempC,
					IsWet:         frame.IsWet,
					FuelLevelL:    frame.FuelLevelL,
					SectorSplits:  meta.SectorSplits,
					Reason:        "session_start",
				})
			}
		} else if frame.TrackTempC > 0 && (frame.IsWet != r.briefedIsWet || math.Abs(float64(frame.TrackTempC-r.briefedTrackTempC)) >= 3.0) {
			meta := r.source.SessionMetadata()
			r.briefedTrackTempC = frame.TrackTempC
			r.briefedIsWet = frame.IsWet
			r.uploader.EnqueueBriefing(transport.BriefingRequest{
				TrackSlug:     meta.TrackSlug,
				TrackName:     meta.TrackName,
				TrackLengthKm: meta.TrackLengthKm,
				CarName:       meta.CarName,
				SessionID:     meta.SessionID,
				SubSessionID:  meta.SubSessionID,
				TrackTempC:    frame.TrackTempC,
				AirTempC:      frame.AirTempC,
				IsWet:         frame.IsWet,
				FuelLevelL:    frame.FuelLevelL,
				Reason:        "conditions_changed",
			})
		}
	}

	// 2. Real Tyre Pyrometer Check upon stopping in Pit Box or exiting to Garage
	if frame.IsOnTrack && !onPitRoad && frame.Sample.Speed > 15.0 {
		r.droveOnTrackThisStint = true
		r.pitDebriefSent = false
		r.pitStoppedTicks = 0
		return
	}

	if r.droveOnTrackThisStint && !r.pitDebriefSent {
		stoppedInPits := frame.IsOnTrack && onPitRoad && frame.Sample.Speed < 0.4
		exitedToGarage := !frame.IsOnTrack

		if stoppedInPits {
			r.pitStoppedTicks++
		}
		if exitedToGarage || r.pitStoppedTicks >= 10 {
			if frame.TyreLFTempC > 25.0 && frame.TyreRFTempC > 25.0 {
				r.uploader.EnqueuePitStop(transport.PitStopRequest{
					LFTempC:       frame.TyreLFTempC,
					RFTempC:       frame.TyreRFTempC,
					LRTempC:       frame.TyreLRTempC,
					RRTempC:       frame.TyreRRTempC,
					LapsCompleted: r.stintLaps,
				})
			}
			r.pitDebriefSent = true
			r.droveOnTrackThisStint = false
			r.pitStoppedTicks = 0
			r.stintLaps = 0
		}
	}
}

// guardLaps returns the Clean Entry Guard distance (250 m) expressed in laps.
func (r *Runner) guardLaps() float64 {
	km := r.source.SessionMetadata().TrackLengthKm
	if km < 1.0 {
		km = 5.0
	}
	g := 0.250 / km
	if g > 0.12 {
		g = 0.12
	}
	return g
}

func (r *Runner) startSector(num int, frame *telemetry.RawFrame, startCum float64) {
	r.secNum = num
	r.secStartIdx = r.ring.Len()
	r.secStartTime = frame.SessionTimeSec
	r.secHadDirty = false
	r.secCleanEntry = !r.dirtyTracked || (startCum-r.lastDirtyCum) > r.guardLaps()
}

func (r *Runner) checkIntermediateSectors(frame *telemetry.RawFrame, currLap int32, currPct float32) {
	splits := r.source.SessionMetadata().SectorSplits
	if len(splits) < 2 {
		return
	}
	prev := r.prevPct
	if currPct <= prev || currPct-prev > 0.25 {
		return
	}
	for k := 1; k < len(splits); k++ {
		b := splits[k]
		if prev < b && b <= currPct {
			if r.secNum == k {
				r.emitSector(frame, currLap, k, splits[k-1], b)
			}
			r.startSector(k+1, frame, float64(currLap)+float64(b))
		}
	}
}

func (r *Runner) emitSector(frame *telemetry.RawFrame, lap int32, num int, startPct, endPct float32) {
	if r.uploader == nil || r.secStartTime <= 0 || frame.SessionTimeSec <= r.secStartTime {
		return
	}
	durMs := (frame.SessionTimeSec - r.secStartTime) * 1000.0
	if durMs < 500 || durMs > 600000 {
		return
	}
	batch := r.ring.SnapshotFrom(r.pool, r.secStartIdx)
	if batch == nil || len(batch.Samples) < 3 {
		if batch != nil {
			r.pool.Release(batch)
		}
		return
	}
	meta := r.source.SessionMetadata()
	r.uploader.EnqueueSector(telemetry.CompletedSector{
		UserID:         r.cfg.UserID,
		TrackSlug:      meta.TrackSlug,
		TrackName:      meta.TrackName,
		TrackLengthKm:  meta.TrackLengthKm,
		CarName:        meta.CarName,
		SessionID:      meta.SessionID,
		SubSessionID:   meta.SubSessionID,
		LapNumber:      lap,
		SectorNumber:   num,
		SectorStartPct: startPct,
		SectorEndPct:   endPct,
		SectorTimeMs:   uint32(durMs),
		IsClean:        r.secCleanEntry && !r.secHadDirty,
		TrackTempC:     frame.TrackTempC,
		AirTempC:       frame.AirTempC,
		IsWet:          frame.IsWet,
		SectorSplits:   meta.SectorSplits,
	}, batch)
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

	var fuelUsedL float32
	if r.lapStartFuelL > crossingFrame.FuelLevelL && (r.lapStartFuelL-crossingFrame.FuelLevelL) < 35.0 {
		fuelUsedL = r.lapStartFuelL - crossingFrame.FuelLevelL
	}

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
		FuelLevelL:    crossingFrame.FuelLevelL,
		FuelUsedL:     fuelUsedL,
		SectorSplits:  meta.SectorSplits,
		SampleRateHz:  r.cfg.SampleRateHz,
	}

	if r.logger != nil {
		r.logger.Info("flying lap completed",
			slog.Int("lap", int(completed.LapNumber)),
			slog.Uint64("lap_time_ms", uint64(completed.LapTimeMs)),
			slog.Bool("is_clean", completed.IsClean),
			slog.Float64("fuel_l", float64(completed.FuelLevelL)),
			slog.Float64("fuel_used_l", float64(completed.FuelUsedL)),
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
