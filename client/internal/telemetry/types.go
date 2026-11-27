package telemetry

import "context"

// SampleFlags encodes per-tick track & cleanliness state in a compact bitmask.
type SampleFlags uint16

const (
	FlagOffTrack  SampleFlags = 1 << 0 // PlayerTrackSurface == OffTrack (0)
	FlagOnPitRoad SampleFlags = 1 << 1 // OnPitRoad == true
	FlagIncident  SampleFlags = 1 << 2 // PlayerCarMyIncidentCount increased
)

// Sample is a pointer-free (noscan) 28-byte telemetry point stored at 20 Hz.
// Keeping this struct strictly free of pointers ensures Go's garbage collector
// never scans the ring buffer backing array, guaranteeing zero GC impact.
type Sample struct {
	TimestampMs uint32      `json:"t_ms"`
	LapDistPct  float32     `json:"pct"`
	Speed       float32     `json:"speed"`
	Throttle    float32     `json:"throttle"`
	Brake       float32     `json:"brake"`
	Lap         int32       `json:"lap"`
	Gear        int16       `json:"gear"`
	Flags       SampleFlags `json:"flags"`
}

// RawFrame wraps a Sample with transient session counters read on the stack
// during each 20 Hz tick to compute relative timestamps, lap times, and cleanliness.
type RawFrame struct {
	Sample            Sample
	SessionTimeSec    float64
	LapLastLapTimeSec float32
	IncidentCount     int32
	TrackTempC        float32
	AirTempC          float32
	IsWet             bool
	IsOnTrack         bool
	FuelLevelL        float32
	TyreLFTempC       float32
	TyreRFTempC       float32
	TyreLRTempC       float32
	TyreRRTempC       float32
}

// SessionMetadata identifies the circuit, car, and iRacing session extracted
// dynamically from the SessionInfo YAML block in shared memory.
type SessionMetadata struct {
	TrackSlug     string  `json:"track_slug"`
	TrackName     string  `json:"track_name"`
	TrackLengthKm float64 `json:"track_length_km"`
	CarName       string  `json:"car_name"`
	SessionID     int     `json:"session_id"`
	SubSessionID  int     `json:"sub_session_id"`
}

// CompletedLap is the payload dispatched asynchronously via HTTP POST
// whenever the driver crosses the start/finish line.
type CompletedLap struct {
	UserID        string  `json:"user_id"`
	TrackSlug     string  `json:"track_slug"`
	TrackName     string  `json:"track_name"`
	TrackLengthKm float64 `json:"track_length_km"`
	CarName       string  `json:"car_name"`
	SessionID     int     `json:"session_id"`
	SubSessionID  int     `json:"sub_session_id"`
	LapNumber     int32   `json:"lap_number"`
	LapTimeMs     uint32  `json:"lap_time_ms"`
	IsClean       bool     `json:"is_clean"`
	IncidentDelta int32    `json:"incident_delta"`
	TrackTempC    float32  `json:"track_temp_c"`
	AirTempC      float32  `json:"air_temp_c"`
	IsWet         bool     `json:"is_wet"`
	FuelLevelL    float32  `json:"fuel_level_l,omitempty"`
	FuelUsedL     float32  `json:"fuel_used_l,omitempty"`
	SampleRateHz  int      `json:"sample_rate_hz"`
	Samples       []Sample `json:"samples"`
}

// Source abstracts telemetry acquisition so the exact same 20 Hz engine runs
// against native Windows shared memory (syscall) and the Linux track simulator.
type Source interface {
	Connect(ctx context.Context) error
	Close() error
	ReadFrame(out *RawFrame) (ok bool, err error)
	SessionMetadata() SessionMetadata
}
