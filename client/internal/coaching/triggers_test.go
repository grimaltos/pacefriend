package coaching

import (
	"context"
	"testing"
)

type recordingSpeaker struct {
	fired []string
}

func (r *recordingSpeaker) Preload(_ context.Context, _ *Plan) error { return nil }
func (r *recordingSpeaker) Speak(t Trigger)                          { r.fired = append(r.fired, t.ID) }

func TestEvaluatorFiresPreBrakeAndWrapAroundExitOncePerLap(t *testing.T) {
	spk := &recordingSpeaker{}
	ev := NewEvaluator(spk)

	ev.SetPlan(&Plan{
		PlanID:        "plan_t1",
		FocusTurn:     1,
		FocusTurnName: "La Source",
		Triggers: []Trigger{
			{ID: "t1_pre_brake", Turn: 1, Phase: PhasePreBrake, TriggerPct: 0.970, Text: "Retrasa diez metros."},
			{ID: "t1_post_exit", Turn: 1, Phase: PhasePostExit, TriggerPct: 0.030, Text: "Abre gas limpio."},
		},
	})

	// Initialize at 0.950 on Lap 2
	ev.Step(2, 0.950, false)
	ev.Step(2, 0.965, false)
	if len(spk.fired) != 0 {
		t.Fatalf("expected 0 triggers before 0.970, got %v", spk.fired)
	}

	// Cross 0.970 -> should fire t1_pre_brake once
	ev.Step(2, 0.975, false)
	if len(spk.fired) != 1 || spk.fired[0] != "t1_pre_brake" {
		t.Fatalf("expected [t1_pre_brake], got %v", spk.fired)
	}

	// Cross start/finish line into Lap 3 (0.992 -> 0.010)
	ev.Step(2, 0.992, false)
	ev.Step(3, 0.010, false)
	if len(spk.fired) != 1 {
		t.Fatalf("expected no extra trigger at S/F line, got %v", spk.fired)
	}

	// Cross 0.030 exit -> should fire t1_post_exit
	ev.Step(3, 0.035, false)
	if len(spk.fired) != 2 || spk.fired[1] != "t1_post_exit" {
		t.Fatalf("expected [t1_pre_brake, t1_post_exit], got %v", spk.fired)
	}

	// On Lap 3 approaching Turn 1 again, t1_pre_brake should re-arm for Lap 3
	ev.Step(3, 0.960, false) // teleport > 0.25 ignored safely, updates cursor to 0.960
	ev.Step(3, 0.972, false)
	if len(spk.fired) != 3 || spk.fired[2] != "t1_pre_brake" {
		t.Fatalf("expected t1_pre_brake to fire again on Lap 3, got %v", spk.fired)
	}
}

type noopSpeaker struct{}

func (noopSpeaker) Preload(_ context.Context, _ *Plan) error { return nil }
func (noopSpeaker) Speak(_ Trigger)                          {}

func TestEvaluatorStepZeroAllocs(t *testing.T) {
	ev := NewEvaluator(noopSpeaker{})
	ev.SetPlan(&Plan{
		PlanID: "bench",
		Triggers: []Trigger{
			{ID: "a", Turn: 1, Phase: PhasePreBrake, TriggerPct: 0.25},
			{ID: "b", Turn: 1, Phase: PhasePostExit, TriggerPct: 0.31},
		},
	})
	ev.Step(1, 0.10, false)

	pct := float32(0.11)
	allocs := testing.AllocsPerRun(1000, func() {
		pct += 0.005
		if pct >= 0.99 {
			pct = 0.01
		}
		ev.Step(2, pct, false)
	})
	if allocs != 0 {
		t.Fatalf("expected 0 allocs in Evaluator.Step, got %f", allocs)
	}
}
