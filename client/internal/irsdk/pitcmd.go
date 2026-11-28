package irsdk

import (
	"strings"
	"sync"
)

// BroadcastMsg constants from irsdk_defines.h.
const (
	BroadcastPitCommand uint16 = 9
)

// PitCommandMode constants from irsdk_defines.h (used with BroadcastPitCommand).
const (
	PitCmdClear      uint16 = 0  // Clear all pit checkboxes
	PitCmdWS         uint16 = 1  // Clean windshield (use tearoff)
	PitCmdFuel       uint16 = 2  // Add fuel (var2 = liters, 0 = default)
	PitCmdLF         uint16 = 3  // Change left-front tyre (var2 = KPa or 0 = setup pressure)
	PitCmdRF         uint16 = 4  // Change right-front tyre
	PitCmdLR         uint16 = 5  // Change left-rear tyre
	PitCmdRR         uint16 = 6  // Change right-rear tyre
	PitCmdClearTires uint16 = 7  // Uncheck all 4 tyres
	PitCmdFastRepair uint16 = 8  // Request fast repair
	PitCmdClearWS    uint16 = 9  // Uncheck windshield tearoff
	PitCmdClearFR    uint16 = 10 // Uncheck fast repair
	PitCmdClearFuel  uint16 = 11 // Uncheck fuel addition
	PitCmdCompound   uint16 = 12 // Select tyre compound (0 = dry/slicks, 1 = wet)
)

// PitCommand represents a high-level pit-box automation instruction sent by the PaceFriend Race Engineer.
type PitCommand struct {
	Action string `json:"action"`          // e.g. "clear_tires", "all_tires", "fuel", "compound", "fast_repair", "tearoff"
	Value  int    `json:"value,omitempty"` // Liters for "fuel", 0 (dry) / 1 (wet) for "compound"
}

type rawPitStep struct {
	mode uint16
	val  int
}

var (
	pitTestMu      sync.Mutex
	pitTestHistory []PitCommand
)

// RecordedPitCommands returns and optionally clears executed pit commands (used in automated tests).
func RecordedPitCommands(clear bool) []PitCommand {
	pitTestMu.Lock()
	defer pitTestMu.Unlock()
	out := make([]PitCommand, len(pitTestHistory))
	copy(out, pitTestHistory)
	if clear {
		pitTestHistory = nil
	}
	return out
}

// ExecutePitCommands expands high-level engineer pit commands into native iRacing SDK broadcast messages.
func ExecutePitCommands(cmds []PitCommand) error {
	if len(cmds) == 0 {
		return nil
	}

	pitTestMu.Lock()
	pitTestHistory = append(pitTestHistory, cmds...)
	pitTestMu.Unlock()

	steps := expandPitSteps(cmds)
	if len(steps) == 0 {
		return nil
	}
	return dispatchPlatformPitSteps(steps)
}

func expandPitSteps(cmds []PitCommand) []rawPitStep {
	steps := make([]rawPitStep, 0, len(cmds)*2)
	for _, c := range cmds {
		switch strings.ToLower(strings.TrimSpace(c.Action)) {
		case "clear_all", "clear":
			steps = append(steps, rawPitStep{mode: PitCmdClear, val: 0})
		case "clear_tires", "no_tires":
			steps = append(steps, rawPitStep{mode: PitCmdClearTires, val: 0})
		case "all_tires":
			steps = append(steps,
				rawPitStep{mode: PitCmdLF, val: 0},
				rawPitStep{mode: PitCmdRF, val: 0},
				rawPitStep{mode: PitCmdLR, val: 0},
				rawPitStep{mode: PitCmdRR, val: 0},
			)
		case "front_tires":
			steps = append(steps,
				rawPitStep{mode: PitCmdClearTires, val: 0},
				rawPitStep{mode: PitCmdLF, val: 0},
				rawPitStep{mode: PitCmdRF, val: 0},
			)
		case "rear_tires":
			steps = append(steps,
				rawPitStep{mode: PitCmdClearTires, val: 0},
				rawPitStep{mode: PitCmdLR, val: 0},
				rawPitStep{mode: PitCmdRR, val: 0},
			)
		case "left_tires":
			steps = append(steps,
				rawPitStep{mode: PitCmdClearTires, val: 0},
				rawPitStep{mode: PitCmdLF, val: 0},
				rawPitStep{mode: PitCmdLR, val: 0},
			)
		case "right_tires":
			steps = append(steps,
				rawPitStep{mode: PitCmdClearTires, val: 0},
				rawPitStep{mode: PitCmdRF, val: 0},
				rawPitStep{mode: PitCmdRR, val: 0},
			)
		case "lf":
			steps = append(steps, rawPitStep{mode: PitCmdLF, val: c.Value})
		case "rf":
			steps = append(steps, rawPitStep{mode: PitCmdRF, val: c.Value})
		case "lr":
			steps = append(steps, rawPitStep{mode: PitCmdLR, val: c.Value})
		case "rr":
			steps = append(steps, rawPitStep{mode: PitCmdRR, val: c.Value})
		case "fuel":
			liters := c.Value
			if liters < 0 {
				liters = 0
			}
			steps = append(steps, rawPitStep{mode: PitCmdFuel, val: liters})
		case "clear_fuel", "no_fuel":
			steps = append(steps, rawPitStep{mode: PitCmdClearFuel, val: 0})
		case "compound":
			comp := c.Value
			if comp < 0 {
				comp = 0
			}
			steps = append(steps, rawPitStep{mode: PitCmdCompound, val: comp})
		case "fast_repair", "fr":
			steps = append(steps, rawPitStep{mode: PitCmdFastRepair, val: 0})
		case "clear_fr", "no_fast_repair":
			steps = append(steps, rawPitStep{mode: PitCmdClearFR, val: 0})
		case "tearoff", "ws", "windshield":
			steps = append(steps, rawPitStep{mode: PitCmdWS, val: 0})
		case "clear_ws":
			steps = append(steps, rawPitStep{mode: PitCmdClearWS, val: 0})
		}
	}
	return steps
}
