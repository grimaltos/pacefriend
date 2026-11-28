package coaching

import (
	"context"
	"log/slog"
	"sync/atomic"
)

// MaxTriggersPerPlan bounds per-lap trigger state in a fixed-size stack/struct array
// so the 20 Hz evaluation loop never allocates on the heap.
const MaxTriggersPerPlan = 16

// Phase identifies whether the engineer message prepares braking or corrects exit.
type Phase string

const (
	PhasePreBrake Phase = "pre_brake"
	PhasePostExit Phase = "post_exit"
)

// Trigger defines a spatial coordinate on track (LapDistPct in [0, 1)) where
// a voice coaching instruction must be played.
type Trigger struct {
	ID          string  `json:"id"`
	Turn        int     `json:"turn"`
	Phase       Phase   `json:"phase"`
	TriggerPct  float32 `json:"trigger_pct"`
	Text        string  `json:"text"`
	AudioURL    string  `json:"audio_url,omitempty"`
	AudioSHA256 string  `json:"audio_sha256,omitempty"`
}

// Plan represents the active coaching instructions returned by the Laravel backend.
type Plan struct {
	PlanID        string    `json:"plan_id"`
	GeneratedAt   string    `json:"generated_at,omitempty"`
	FocusTurn     int       `json:"focus_turn"`
	FocusTurnName string    `json:"focus_turn_name"`
	AvgTimeLossMs int       `json:"avg_time_loss_ms"`
	Triggers      []Trigger `json:"triggers"`
}

// Callout represents an immediate non-spatial engineer message (out-lap briefing,
// start/finish lap time & positive reinforcement, fuel alert, or pit tyre debrief).
type Callout struct {
	ID          string `json:"id"`
	Text        string `json:"text"`
	AudioURL    string `json:"audio_url,omitempty"`
	AudioSHA256 string `json:"audio_sha256,omitempty"`
}

// ImmediateSpeaker is optionally implemented by Speaker engines capable of dynamic
// volume control and immediate non-spatial radio callouts.
type ImmediateSpeaker interface {
	PlayCallouts(ctx context.Context, callouts []Callout) error
	SetVolume(pct int)
}

// Speaker abstracts non-blocking radio callout playback (console logger in Phase 1,
// in-memory preloaded AI voice clips in Phase 4).
type Speaker interface {
	// Preload warms AI-generated audio assets in background RAM when a plan arrives.
	Preload(ctx context.Context, plan *Plan) error
	// Speak triggers immediate non-blocking playback for the given spatial trigger.
	Speak(trigger Trigger)
}

// LogSpeaker is the default lightweight radio output used until Phase 4 audio is attached,
// or alongside audio in verbose/simulator runs.
type LogSpeaker struct {
	Logger *slog.Logger
}

func (l *LogSpeaker) Preload(_ context.Context, _ *Plan) error {
	return nil
}

func (l *LogSpeaker) Speak(t Trigger) {
	if l.Logger != nil {
		l.Logger.Info("RADIO ENGINEER",
			slog.Int("turn", t.Turn),
			slog.String("phase", string(t.Phase)),
			slog.Float64("pct", float64(t.TriggerPct)),
			slog.String("message", t.Text),
		)
	}
}

// Evaluator monitors LapDistPct progression at 20 Hz and fires spatial triggers
// with lock-free plan updates (atomic.Pointer) and zero heap allocations.
type Evaluator struct {
	activePlan   atomic.Pointer[Plan]
	silenced     atomic.Bool
	speaker      Speaker
	seenPlanPtr  *Plan
	lastFiredLap [MaxTriggersPerPlan]int32
	prevPct      float32
	initialized  bool
}

// NewEvaluator creates a lock-free spatial trigger evaluator.
func NewEvaluator(speaker Speaker) *Evaluator {
	return &Evaluator{speaker: speaker}
}

// SetSilenced enables or disables temporary combat/qualifying silence without losing track position.
func (e *Evaluator) SetSilenced(silenced bool) {
	e.silenced.Store(silenced)
}

// IsSilenced reports whether technical corner triggers are currently muted (e.g. close wheel-to-wheel battle).
func (e *Evaluator) IsSilenced() bool {
	return e.silenced.Load()
}

// SetPlan atomically swaps the active coaching plan from any background goroutine.
func (e *Evaluator) SetPlan(plan *Plan) {
	e.activePlan.Store(plan)
}

// ActivePlan returns the currently active coaching plan, if any.
func (e *Evaluator) ActivePlan() *Plan {
	return e.activePlan.Load()
}

// ResetCursor clears previous position tracking (e.g. after pit tow or session reset).
func (e *Evaluator) ResetCursor() {
	e.initialized = false
}

// Step evaluates spatial crossings between the previous sample and current sample.
// Must be called sequentially from the 20 Hz sampling goroutine (0 allocs/op).
func (e *Evaluator) Step(currLap int32, currPct float32, onPitRoad bool) {
	if onPitRoad || currLap < 1 {
		e.initialized = false
		return
	}

	plan := e.activePlan.Load()
	if plan != e.seenPlanPtr {
		e.seenPlanPtr = plan
		for i := range e.lastFiredLap {
			e.lastFiredLap[i] = -1
		}
	}

	if !e.initialized {
		e.prevPct = currPct
		e.initialized = true
		return
	}

	prevPct := e.prevPct
	e.prevPct = currPct

	if e.silenced.Load() || plan == nil || len(plan.Triggers) == 0 || e.speaker == nil {
		return
	}

	// Compute forward delta along [0, 1) and reject sudden teleports (> 25% of track in 50 ms).
	wrapped := false
	delta := currPct - prevPct
	if delta < 0 {
		delta = (1.0 - prevPct) + currPct
		wrapped = true
	}
	if delta <= 0 || delta > 0.25 {
		return
	}

	limit := len(plan.Triggers)
	if limit > MaxTriggersPerPlan {
		limit = MaxTriggersPerPlan
	}

	for i := 0; i < limit; i++ {
		trg := plan.Triggers[i]
		target := trg.TriggerPct

		var crossed bool
		effectiveLap := currLap

		if !wrapped {
			crossed = prevPct < target && target <= currPct
		} else {
			if target > prevPct {
				crossed = true
				effectiveLap = currLap - 1
			} else if target <= currPct {
				crossed = true
				effectiveLap = currLap
			}
		}

		if crossed && effectiveLap >= 1 && e.lastFiredLap[i] != effectiveLap {
			e.lastFiredLap[i] = effectiveLap
			e.speaker.Speak(trg)
		}
	}
}
