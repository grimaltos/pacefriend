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
	OnLiveState      func(telemetry.SessionMetadata, telemetry.RaceContext)
}

// Runner coordinates the 20 Hz sampling ticker, zero-GC ring buffer, lap-crossing
// detector, spatial trigger evaluator, race combat discipline, and non-blocking HTTP uploader.
type Runner struct {
	cfg       Config
	source    telemetry.Source
	ring      *buffer.RingBuffer
	pool      *buffer.SnapshotPool
	evaluator *coaching.Evaluator
	uploader  *transport.Uploader
	logger    *slog.Logger

	cachedMeta        telemetry.SessionMetadata
	metaLoaded        bool
	lastRaceCtx       telemetry.RaceContext
	lapHadTraffic     bool
	lastHazardTimeSec float64

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
	briefedSessionType    string
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
	r.cachedMeta = lastMeta
	r.metaLoaded = true
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
			if metaCheckTick >= r.cfg.SampleRateHz {
				metaCheckTick = 0
				curMeta := r.source.SessionMetadata()
				r.cachedMeta = curMeta
				if r.cfg.OnMetadataChange != nil && (curMeta.TrackName != lastMeta.TrackName || curMeta.CarName != lastMeta.CarName) {
					lastMeta = curMeta
					r.cfg.OnMetadataChange(curMeta)
				}
				if r.cfg.OnLiveState != nil {
					r.cfg.OnLiveState(curMeta, r.lastRaceCtx)
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
	if !r.metaLoaded {
		r.cachedMeta = r.source.SessionMetadata()
		r.metaLoaded = true
	}

	currLap := frame.Sample.Lap
	currPct := frame.Sample.LapDistPct
	onPitRoad := frame.Sample.Flags&telemetry.FlagOnPitRoad != 0

	rc, hazardAheadMeters := r.computeLiveRaceContext(frame, &r.cachedMeta)
	r.lastRaceCtx = rc

	if r.evaluator != nil {
		inCombat := rc.SessionType == "race" && rc.InTraffic
		qualyFlying := rc.SessionType == "qualify" && r.fullFlyingLap && !r.lapDirty
		r.evaluator.SetSilenced(inCombat || qualyFlying)
	}

	if rc.InTraffic {
		r.lapHadTraffic = true
	}

	// Immediate ahead hazard warning (spun/off-track car 130..480m ahead in Race/Qualy)
	if hazardAheadMeters > 0 && r.uploader != nil && (frame.SessionTimeSec-r.lastHazardTimeSec) > 22.0 && frame.SessionTimeSec > 5.0 {
		r.lastHazardTimeSec = frame.SessionTimeSec
		r.uploader.EnqueueHazardAlert(transport.HazardAlertRequest{
			TrackSlug:   r.cachedMeta.TrackSlug,
			CarName:     r.cachedMeta.CarName,
			AheadMeters: hazardAheadMeters,
			LapDistPct:  currPct,
		})
	}

	r.evaluateBriefingAndPitTyres(frame, onPitRoad, &rc)

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
		r.lapHadTraffic = rc.InTraffic
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

	if frame.Sample.Flags&telemetry.FlagsDirtyMask != 0 {
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

func (r *Runner) evaluateBriefingAndPitTyres(frame *telemetry.RawFrame, onPitRoad bool, rc *telemetry.RaceContext) {
	if r.uploader == nil {
		return
	}

	// 1. Out-lap / Grid / Session Start Greeting & Session/Weather-Shift Alerts
	// In Race mode, send briefing as soon as driver enters grid (even before moving off pit/grid slot!)
	readyForBriefing := frame.IsOnTrack && (!onPitRoad || rc.SessionType == "race")
	sessionChanged := r.briefingSent && rc.SessionType != "" && r.briefedSessionType != "" && rc.SessionType != r.briefedSessionType

	if readyForBriefing {
		if !r.briefingSent || sessionChanged {
			meta := r.cachedMeta
			if meta.TrackSlug != "" && meta.CarName != "" {
				r.briefingSent = true
				r.briefedSessionType = rc.SessionType
				r.briefedTrackTempC = frame.TrackTempC
				r.briefedIsWet = frame.IsWet
				rcCopy := *rc
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
					Race:          &rcCopy,
				})
			}
		} else if !onPitRoad && frame.TrackTempC > 0 && (frame.IsWet != r.briefedIsWet || math.Abs(float64(frame.TrackTempC-r.briefedTrackTempC)) >= 3.0) {
			meta := r.cachedMeta
			r.briefedTrackTempC = frame.TrackTempC
			r.briefedIsWet = frame.IsWet
			rcCopy := *rc
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
				Race:          &rcCopy,
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
	meta := r.cachedMeta
	rcCopy := r.lastRaceCtx
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
		IsClean:        r.secCleanEntry && !r.secHadDirty && !rcCopy.InTraffic,
		TrackTempC:     frame.TrackTempC,
		AirTempC:       frame.AirTempC,
		IsWet:          frame.IsWet,
		SectorSplits:   meta.SectorSplits,
		Race:           &rcCopy,
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

	meta := r.cachedMeta
	rcCopy := r.lastRaceCtx
	if r.lapHadTraffic {
		rcCopy.InTraffic = true
	}
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
		Race:          &rcCopy,
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

func (r *Runner) computeLiveRaceContext(frame *telemetry.RawFrame, meta *telemetry.SessionMetadata) (telemetry.RaceContext, int) {
	rc := telemetry.RaceContext{
		SessionType:        meta.SessionTypeFor(frame.SessionNum),
		SessionState:       frame.SessionState,
		SessionFlags:       frame.SessionFlags,
		TimeRemainSec:      frame.SessionTimeRemainSec,
		LapsRemain:         frame.SessionLapsRemain,
		IncidentCount:      frame.IncidentCount,
		IncidentLimit:      meta.IncidentLimit,
		ClassPosition:      frame.PlayerClassPosition,
		OverallPosition:    frame.PlayerPosition,
		Precipitation:      frame.Precipitation,
		TrackWetnessLevel:  frame.TrackWetnessLevel,
		WeatherDeclaredWet: frame.WeatherDeclaredWet,
		WindSpeedKmh:       frame.WindVelMS * 3.6,
		PlayerCompound:     frame.PlayerTireCompound,
		PitSvFlags:         frame.PitSvFlags,
		PitSvFuelL:         frame.PitSvFuelL,
		PitSvCompound:      frame.PitSvCompound,
		FuelLevelL:         frame.FuelLevelL,
		TrackTempC:         frame.TrackTempC,
		AirTempC:           frame.AirTempC,
		StintLaps:          r.stintLaps,
	}

	if !frame.HasGridArrays {
		return rc, 0
	}

	pIdx := int(frame.PlayerCarIdx)
	if pIdx < 0 || pIdx >= telemetry.MaxSimCars {
		pIdx = meta.DriverCarIdx
	}
	if pIdx < 0 || pIdx >= telemetry.MaxSimCars {
		return rc, 0
	}

	if rc.ClassPosition <= 0 {
		rc.ClassPosition = frame.CarIdxClassPosition[pIdx]
	}

	trackMeters := float32(meta.TrackLengthKm * 1000.0)
	if trackMeters < 1000.0 {
		trackMeters = 5000.0
	}
	estLapSec := trackMeters / 48.0
	if frame.LapLastLapTimeSec > 25.0 && frame.LapLastLapTimeSec < 400.0 {
		estLapSec = frame.LapLastLapTimeSec
	}

	playerClassID := meta.CarClassIDs[pIdx]
	playerRelSpeed := meta.CarClassRelSpeed[pIdx]
	playerPct := frame.Sample.LapDistPct

	var drySumMs int64
	var wetSumMs int64
	var closestQualyAheadSec float32
	var closestFasterClassSec float32
	hazardMeters := 0

	for i := 0; i < telemetry.MaxSimCars; i++ {
		if i == pIdx || meta.CarIsSpectator[i] {
			continue
		}
		cPct := frame.CarIdxLapDistPct[i]
		cPos := frame.CarIdxClassPosition[i]
		if frame.CarIdxLap[i] <= 0 && cPos <= 0 && cPct <= 0 {
			continue
		}

		sameClass := playerClassID == 0 || meta.CarClassIDs[i] == 0 || meta.CarClassIDs[i] == playerClassID
		if !sameClass {
			rc.IsMulticlass = true
			if playerRelSpeed > 0 && meta.CarClassRelSpeed[i] > playerRelSpeed+4 && !frame.CarIdxOnPitRoad[i] && cPct >= 0 {
				behindPct := wrapUnitPct(playerPct - cPct)
				if behindPct > 0.002 && behindPct < 0.25 {
					closingSec := behindPct * estLapSec
					if closestFasterClassSec == 0 || closingSec < closestFasterClassSec {
						closestFasterClassSec = closingSec
					}
				}
			}
		} else {
			rc.CarsInClass++

			// Aggregate live dry vs wet lap pace across same-class cars for Crossover detection
			lastS := frame.CarIdxLastLapTime[i]
			if lastS > 25.0 && lastS < 500.0 && !frame.CarIdxOnPitRoad[i] {
				ms := int64(lastS * 1000.0)
				if frame.CarIdxTireCompound[i] >= 1 {
					wetSumMs += ms
					rc.WetCarsCount++
				} else {
					drySumMs += ms
					rc.DryCarsCount++
				}
			}

			if rc.SessionType == "race" && rc.ClassPosition > 0 && cPos > 0 {
				if cPos == rc.ClassPosition-1 {
					rc.GapAheadSec = computeCarGapSec(cPct, playerPct, frame.CarIdxEstTime[i], frame.CarIdxEstTime[pIdx], estLapSec)
					rc.AheadLastLapMs = int32(frame.CarIdxLastLapTime[i] * 1000.0)
					rc.AheadBestLapMs = int32(frame.CarIdxBestLapTime[i] * 1000.0)
					rc.AheadOnPit = frame.CarIdxOnPitRoad[i]
				} else if cPos == rc.ClassPosition+1 {
					rc.GapBehindSec = computeCarGapSec(playerPct, cPct, frame.CarIdxEstTime[pIdx], frame.CarIdxEstTime[i], estLapSec)
					rc.BehindLastLapMs = int32(frame.CarIdxLastLapTime[i] * 1000.0)
					rc.BehindBestLapMs = int32(frame.CarIdxBestLapTime[i] * 1000.0)
				}
			} else if rc.SessionType == "qualify" && !frame.CarIdxOnPitRoad[i] && cPct >= 0 {
				aheadPct := wrapUnitPct(cPct - playerPct)
				if aheadPct > 0.002 && aheadPct < 0.35 {
					sec := aheadPct * estLapSec
					if closestQualyAheadSec == 0 || sec < closestQualyAheadSec {
						closestQualyAheadSec = sec
					}
				}
			}
		}

		// Ahead hazard radar: off-track car 130..480m ahead while approaching at racing speed
		if rc.SessionType != "practice" && !frame.CarIdxOnPitRoad[i] && frame.CarIdxTrackSurface[i] == 0 && frame.Sample.Speed > 28.0 && cPct >= 0 {
			aheadPct := wrapUnitPct(cPct - playerPct)
			aheadM := aheadPct * trackMeters
			if aheadM >= 130.0 && aheadM <= 480.0 {
				m := int(aheadM)
				if hazardMeters == 0 || m < hazardMeters {
					hazardMeters = m
				}
			}
		}
	}

	rc.CarsInClass++ // Include player car itself
	if rc.DryCarsCount > 0 {
		rc.DryCarsAvgLapMs = int32(drySumMs / int64(rc.DryCarsCount))
	}
	if rc.WetCarsCount > 0 {
		rc.WetCarsAvgLapMs = int32(wetSumMs / int64(rc.WetCarsCount))
	}
	if rc.SessionType == "qualify" && closestQualyAheadSec > 0 {
		rc.GapAheadSec = closestQualyAheadSec
	}
	rc.FasterClassClosingSec = closestFasterClassSec

	if rc.SessionType == "race" && ((rc.GapAheadSec > 0.05 && rc.GapAheadSec < 1.35) || (rc.GapBehindSec > 0.05 && rc.GapBehindSec < 0.90)) {
		rc.InTraffic = true
	}

	return rc, hazardMeters
}

func wrapUnitPct(d float32) float32 {
	if d < 0 {
		d += 1.0
	}
	if d >= 1.0 {
		d -= 1.0
	}
	return d
}

func computeCarGapSec(leaderPct, trailerPct, leaderEst, trailerEst, estLapSec float32) float32 {
	pctDiff := wrapUnitPct(leaderPct - trailerPct)
	if leaderEst > 0 && trailerEst > 0 {
		estDiff := leaderEst - trailerEst
		if estDiff < 0 {
			estDiff += estLapSec
		}
		if estDiff > 0.05 && estDiff < estLapSec {
			return estDiff
		}
	}
	return pctDiff * estLapSec
}
