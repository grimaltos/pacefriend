package irsdk

import (
	"context"
	"encoding/binary"
	"math"
	"time"

	"github.com/pacefriend/client/internal/telemetry"
)

// SimulatorConfig controls the synthetic iRacing binary memory simulator.
type SimulatorConfig struct {
	Speedup       float64 // Time multiplier (1.0 = real time, 10.0 = 10x faster laps)
	DirtyEveryLap int     // If > 0, marks every Nth flying lap with an off-track excursion
}

// cornerZone defines a simplified braking/apex/exit profile along LapDistPct.
type cornerZone struct {
	brakeStart float32
	apex       float32
	exit       float32
	minSpeedMS float32
	minGear    int32
}

var spaCorners = []cornerZone{
	{brakeStart: 0.000, apex: 0.014, exit: 0.032, minSpeedMS: 19.5, minGear: 1}, // T1 La Source (exit half)
	{brakeStart: 0.275, apex: 0.295, exit: 0.325, minSpeedMS: 33.0, minGear: 3}, // T5 Les Combes
	{brakeStart: 0.425, apex: 0.448, exit: 0.472, minSpeedMS: 26.0, minGear: 2}, // T8 Bruxelles
	{brakeStart: 0.610, apex: 0.630, exit: 0.655, minSpeedMS: 44.0, minGear: 4}, // T12 Pouhon
	{brakeStart: 0.895, apex: 0.920, exit: 0.948, minSpeedMS: 21.0, minGear: 2}, // T18 Chicane
	{brakeStart: 0.968, apex: 0.995, exit: 1.000, minSpeedMS: 20.5, minGear: 1}, // T1 La Source (chronic early braking entry)
}

const simSessionYAML = `---
WeekendInfo:
 TrackName: spa up
 TrackLength: 7.00 km
 TrackDisplayName: Circuit de Spa-Francorchamps
 SessionID: 104521
 SubSessionID: 68912044
SplitTimeInfo:
 Sectors:
 - SectorNum: 0
   SectorStartPct: 0.000000
 - SectorNum: 1
   SectorStartPct: 0.341000
 - SectorNum: 2
   SectorStartPct: 0.685000
DriverInfo:
 DriverCarIdx: 4
 Drivers:
 - CarIdx: 0
   CarScreenName: BMW M4 GT3
 - CarIdx: 4
   CarScreenName: Porsche 911 GT3 R (992)
   CarPath: porsche992rgt3
`

// SimulatorSource synthesizes an authentic in-memory iRacing SDK shared memory
// block (header, varHeaders, SessionInfo YAML, and triple-buffered telemetry rows)
// and reads frames back through the production binary parser.
type SimulatorSource struct {
	cfg           SimulatorConfig
	mem           []byte
	varIndex      *VarIndex
	meta          telemetry.SessionMetadata
	lastWallClock time.Time

	simTimeSec      float64
	lapStartTimeSec float64
	lastLapTimeSec  float32
	distMeters      float64
	trackLenMeters  float64
	lap             int32
	incidents       int32
	tickCount       int32
	bufCursor       int
}

// NewSimulatorSource constructs a cross-platform iRacing shared memory simulator.
func NewSimulatorSource(cfg SimulatorConfig) *SimulatorSource {
	if cfg.Speedup <= 0 {
		cfg.Speedup = 1.0
	}
	return &SimulatorSource{
		cfg:            cfg,
		trackLenMeters: 7004.0,
		// Start on out-lap near Bus Stop (94% of lap 0) so crossing into Lap 1 happens quickly.
		distMeters: 7004.0 * 0.94,
		lap:        0,
	}
}

func (s *SimulatorSource) Connect(_ context.Context) error {
	s.mem = buildSyntheticShmBlock(simSessionYAML)
	var hdr Header
	if err := ParseHeader(s.mem, &hdr); err != nil {
		return err
	}
	idx, err := IndexVariables(s.mem, &hdr)
	if err != nil {
		return err
	}
	s.varIndex = idx
	s.meta = ParseSessionInfoYAML(s.mem[hdr.SessionInfoOffset : hdr.SessionInfoOffset+hdr.SessionInfoLen])
	s.lastWallClock = time.Now()
	s.advancePhysics(0)
	return nil
}

func (s *SimulatorSource) ReadFrame(out *telemetry.RawFrame) (bool, error) {
	now := time.Now()
	dt := now.Sub(s.lastWallClock).Seconds() * s.cfg.Speedup
	s.lastWallClock = now
	if dt < 0 {
		dt = 0
	}
	if dt > 0.25*s.cfg.Speedup {
		dt = 0.05 * s.cfg.Speedup
	}

	s.advancePhysics(dt)
	return ReadFrameFromMemory(s.mem, s.varIndex, out)
}

func (s *SimulatorSource) SessionMetadata() telemetry.SessionMetadata {
	return s.meta
}

func (s *SimulatorSource) Close() error {
	s.mem = nil
	s.varIndex = nil
	return nil
}

func (s *SimulatorSource) advancePhysics(dt float64) {
	pct := float32(s.distMeters / s.trackLenMeters)
	speed, throttle, brake, gear := computeKinematics(pct)

	// Advance position along circuit
	s.simTimeSec += dt
	s.distMeters += float64(speed) * dt
	if s.distMeters >= s.trackLenMeters {
		s.distMeters -= s.trackLenMeters
		if s.lap >= 1 {
			s.lastLapTimeSec = float32(s.simTimeSec - s.lapStartTimeSec)
		}
		s.lap++
		s.lapStartTimeSec = s.simTimeSec
		pct = float32(s.distMeters / s.trackLenMeters)
		speed, throttle, brake, gear = computeKinematics(pct)
	}

	surface := int32(3) // irsdk_OnTrack
	if s.cfg.DirtyEveryLap > 0 && s.lap > 0 && int(s.lap)%s.cfg.DirtyEveryLap == 0 {
		if pct > 0.30 && pct < 0.32 {
			surface = TrackSurfaceOff
		}
	}

	// Write into next slot of the 3-slot triple buffer just like iRacing's 60 Hz engine.
	s.tickCount++
	s.bufCursor = (s.bufCursor + 1) % 3
	hdrVarBufBase := 48 + s.bufCursor*VarBufSize
	bufOffset := int(binary.LittleEndian.Uint32(s.mem[hdrVarBufBase+4 : hdrVarBufBase+8]))
	row := s.mem[bufOffset : bufOffset+simRowBufLen]

	binary.LittleEndian.PutUint32(row[0:4], math.Float32bits(speed))
	binary.LittleEndian.PutUint32(row[4:8], math.Float32bits(brake))
	binary.LittleEndian.PutUint32(row[8:12], math.Float32bits(throttle))
	binary.LittleEndian.PutUint32(row[12:16], uint32(s.lap))
	binary.LittleEndian.PutUint32(row[16:20], math.Float32bits(pct))
	binary.LittleEndian.PutUint32(row[20:24], uint32(gear))
	binary.LittleEndian.PutUint64(row[24:32], math.Float64bits(s.simTimeSec))
	binary.LittleEndian.PutUint32(row[32:36], math.Float32bits(s.lastLapTimeSec))
	binary.LittleEndian.PutUint32(row[36:40], uint32(surface))
	row[40] = 0 // OnPitRoad = false
	binary.LittleEndian.PutUint32(row[44:48], uint32(s.incidents))
	binary.LittleEndian.PutUint32(row[48:52], math.Float32bits(31.5)) // TrackTempCrew (°C)
	binary.LittleEndian.PutUint32(row[52:56], math.Float32bits(22.0)) // AirTemp (°C)
	binary.LittleEndian.PutUint32(row[56:60], 1)                      // TrackWetness = 1 (Dry)
	fuelL := float32(math.Max(2.0, 48.0-(float64(s.lap)+float64(pct))*2.8))
	binary.LittleEndian.PutUint32(row[60:64], math.Float32bits(fuelL)) // FuelLevel (L)
	row[64] = 1                                                        // IsOnTrack = true

	// Publish updated tickCount for this buffer slot
	binary.LittleEndian.PutUint32(s.mem[hdrVarBufBase:hdrVarBufBase+4], uint32(s.tickCount))
}

func computeKinematics(pct float32) (speed, throttle, brake float32, gear int32) {
	const topSpeed float32 = 73.5 // ~265 km/h

	for _, c := range spaCorners {
		if pct >= c.brakeStart && pct <= c.exit {
			if pct <= c.apex {
				// Braking phase into apex
				span := c.apex - c.brakeStart
				progress := float32(1.0)
				if span > 0 {
					progress = (pct - c.brakeStart) / span
				}
				speed = topSpeed - (topSpeed-c.minSpeedMS)*progress
				brake = 0.92 * (1.0 - 0.55*progress)
				throttle = 0.0
			} else {
				// Exit acceleration out of apex
				span := c.exit - c.apex
				progress := float32(1.0)
				if span > 0 {
					progress = (pct - c.apex) / span
				}
				speed = c.minSpeedMS + (54.0-c.minSpeedMS)*progress
				brake = 0.0
				throttle = 0.35 + 0.65*progress
			}
			gear = gearForSpeed(speed, c.minGear)
			return speed, throttle, brake, gear
		}
	}

	return topSpeed, 1.0, 0.0, 6
}

func gearForSpeed(speedMS float32, minGear int32) int32 {
	var g int32
	switch {
	case speedMS < 24:
		g = 1
	case speedMS < 34:
		g = 2
	case speedMS < 45:
		g = 3
	case speedMS < 56:
		g = 4
	case speedMS < 66:
		g = 5
	default:
		g = 6
	}
	if g < minGear {
		return minGear
	}
	return g
}

const simRowBufLen = 80

type simVarDef struct {
	name    string
	varType VarType
	offset  int32
}

var simVars = []simVarDef{
	{name: "Speed", varType: VarTypeFloat, offset: 0},
	{name: "Brake", varType: VarTypeFloat, offset: 4},
	{name: "Throttle", varType: VarTypeFloat, offset: 8},
	{name: "Lap", varType: VarTypeInt, offset: 12},
	{name: "LapDistPct", varType: VarTypeFloat, offset: 16},
	{name: "Gear", varType: VarTypeInt, offset: 20},
	{name: "SessionTime", varType: VarTypeDouble, offset: 24},
	{name: "LapLastLapTime", varType: VarTypeFloat, offset: 32},
	{name: "PlayerTrackSurface", varType: VarTypeInt, offset: 36},
	{name: "OnPitRoad", varType: VarTypeBool, offset: 40},
	{name: "PlayerCarMyIncidentCount", varType: VarTypeInt, offset: 44},
	{name: "TrackTempCrew", varType: VarTypeFloat, offset: 48},
	{name: "AirTemp", varType: VarTypeFloat, offset: 52},
	{name: "TrackWetness", varType: VarTypeInt, offset: 56},
	{name: "FuelLevel", varType: VarTypeFloat, offset: 60},
	{name: "IsOnTrack", varType: VarTypeBool, offset: 64},
}

func buildSyntheticShmBlock(yamlText string) []byte {
	varHdrOffset := HeaderSize
	varHdrLen := len(simVars) * VarHeaderSize
	sessionOffset := varHdrOffset + varHdrLen
	yamlBytes := append([]byte(yamlText), 0)
	// Align data buffers to 16-byte boundary
	bufBase := (sessionOffset + len(yamlBytes) + 15) &^ 15
	totalLen := bufBase + 3*simRowBufLen

	mem := make([]byte, totalLen)

	// Populate 112-byte irsdk_header
	binary.LittleEndian.PutUint32(mem[0:4], 2)                      // ver = 2
	binary.LittleEndian.PutUint32(mem[4:8], StatusConnected)        // status = connected
	binary.LittleEndian.PutUint32(mem[8:12], 60)                    // tickRate = 60 Hz
	binary.LittleEndian.PutUint32(mem[12:16], 1)                    // sessionInfoUpdate = 1
	binary.LittleEndian.PutUint32(mem[16:20], uint32(len(yamlBytes)))
	binary.LittleEndian.PutUint32(mem[20:24], uint32(sessionOffset))
	binary.LittleEndian.PutUint32(mem[24:28], uint32(len(simVars)))
	binary.LittleEndian.PutUint32(mem[28:32], uint32(varHdrOffset))
	binary.LittleEndian.PutUint32(mem[32:36], 3) // triple buffer
	binary.LittleEndian.PutUint32(mem[36:40], simRowBufLen)

	for i := 0; i < 3; i++ {
		base := 48 + i*VarBufSize
		binary.LittleEndian.PutUint32(mem[base:base+4], 0) // tickCount
		binary.LittleEndian.PutUint32(mem[base+4:base+8], uint32(bufBase+i*simRowBufLen))
	}

	// Populate irsdk_varHeader entries
	for i, v := range simVars {
		entry := mem[varHdrOffset+i*VarHeaderSize : varHdrOffset+(i+1)*VarHeaderSize]
		binary.LittleEndian.PutUint32(entry[0:4], uint32(v.varType))
		binary.LittleEndian.PutUint32(entry[4:8], uint32(v.offset))
		binary.LittleEndian.PutUint32(entry[8:12], 1) // count = 1
		copy(entry[16:16+MaxStringLen-1], v.name)
	}

	// Copy SessionInfo YAML
	copy(mem[sessionOffset:sessionOffset+len(yamlBytes)], yamlBytes)

	return mem
}
