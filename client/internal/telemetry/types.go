package telemetry

import "context"

// MaxSimCars matches iRacing's maximum 64-car telemetry array dimension.
const MaxSimCars = 64

// SampleFlags encodes per-tick track & cleanliness state in a compact bitmask.
type SampleFlags uint16

const (
	FlagOffTrack   SampleFlags = 1 << 0 // PlayerTrackSurface == OffTrack (0)
	FlagOnPitRoad  SampleFlags = 1 << 1 // OnPitRoad == true
	FlagIncident   SampleFlags = 1 << 2 // PlayerCarMyIncidentCount increased
	FlagABSActive  SampleFlags = 1 << 3 // BrakeABSactive == true
	FlagCurbStrike SampleFlags = 1 << 4 // TireLF/RF_RumblePitch > 0 (riding kerb)

	// FlagsDirtyMask isolates cleanliness-invalidating flags so telemetry sensor bits
	// (ABS, CurbStrike) never mark a lap or partial dirty.
	FlagsDirtyMask SampleFlags = FlagOffTrack | FlagOnPitRoad | FlagIncident
)

// Sample is a pointer-free (noscan) 64-byte telemetry point stored at 20 Hz.
// Keeping this struct strictly free of pointers ensures Go's garbage collector
// never scans the ring buffer backing array, guaranteeing zero GC impact.
type Sample struct {
	Lat         float64     `json:"lat,omitempty"`
	Lon         float64     `json:"lon,omitempty"`
	TimestampMs uint32      `json:"t_ms"`
	LapDistPct  float32     `json:"pct"`
	Speed       float32     `json:"speed"`
	Throttle    float32     `json:"throttle"`
	Brake       float32     `json:"brake"`
	Steer       float32     `json:"steer,omitempty"`
	YawRate     float32     `json:"yaw,omitempty"`
	LatG        float32     `json:"lat_g,omitempty"`
	LongG       float32     `json:"long_g,omitempty"`
	Lap         int32       `json:"lap"`
	Gear        int16       `json:"gear"`
	Flags       SampleFlags `json:"flags"`
}

// RaceContext summarizes real-time race, qualifying, weather/crossover, rival gaps,
// multiclass traffic, and Black Box pit-service state for the Race Engineer.
type RaceContext struct {
	SessionType           string  `json:"session_type"` // "practice", "qualify", "race"
	SessionState          int32   `json:"session_state,omitempty"`
	SessionFlags          uint32  `json:"session_flags,omitempty"`
	TimeRemainSec         float64 `json:"time_remain_sec,omitempty"`
	LapsRemain            int32   `json:"laps_remain,omitempty"`
	IncidentCount         int32   `json:"incident_count,omitempty"`
	IncidentLimit         int     `json:"incident_limit,omitempty"`
	ClassPosition         int32   `json:"class_position,omitempty"`
	OverallPosition       int32   `json:"overall_position,omitempty"`
	CarsInClass           int     `json:"cars_in_class,omitempty"`
	IsMulticlass          bool    `json:"is_multiclass,omitempty"`
	GapAheadSec           float32 `json:"gap_ahead_s,omitempty"`
	AheadLastLapMs        int32   `json:"ahead_last_lap_ms,omitempty"`
	AheadBestLapMs        int32   `json:"ahead_best_lap_ms,omitempty"`
	AheadOnPit            bool    `json:"ahead_on_pit,omitempty"`
	GapBehindSec          float32 `json:"gap_behind_s,omitempty"`
	BehindLastLapMs       int32   `json:"behind_last_lap_ms,omitempty"`
	BehindBestLapMs       int32   `json:"behind_best_lap_ms,omitempty"`
	FasterClassClosingSec float32 `json:"faster_class_closing_s,omitempty"`
	InTraffic             bool    `json:"in_traffic,omitempty"`
	Precipitation         float32 `json:"precipitation,omitempty"`
	TrackWetnessLevel     int32   `json:"track_wetness_level,omitempty"`
	WeatherDeclaredWet    bool    `json:"weather_declared_wet,omitempty"`
	WindSpeedKmh          float32 `json:"wind_speed_kmh,omitempty"`
	PlayerCompound        int32   `json:"player_compound"`
	DryCarsCount          int     `json:"dry_cars_count,omitempty"`
	DryCarsAvgLapMs       int32   `json:"dry_cars_avg_lap_ms,omitempty"`
	WetCarsCount          int     `json:"wet_cars_count,omitempty"`
	WetCarsAvgLapMs       int32   `json:"wet_cars_avg_lap_ms,omitempty"`
	PitSvFlags            uint32  `json:"pit_sv_flags,omitempty"`
	PitSvFuelL            float32 `json:"pit_sv_fuel_l,omitempty"`
	PitSvCompound         int32   `json:"pit_sv_compound,omitempty"`
	FuelLevelL            float32 `json:"fuel_level_l,omitempty"`
	TrackTempC            float32 `json:"track_temp_c,omitempty"`
	AirTempC              float32 `json:"air_temp_c,omitempty"`
	StintLaps             int     `json:"stint_laps,omitempty"`
}

// RawFrame wraps a Sample with transient session, weather, pit-box, and 64-car radar counters
// read on the stack during each 20 Hz tick (zero heap allocations).
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

	// Session clock, flags, weather & pit-service state
	SessionNum            int32
	SessionState          int32
	SessionFlags          uint32
	SessionTimeRemainSec  float64
	SessionLapsRemain     int32
	Precipitation         float32
	TrackWetnessLevel     int32
	WeatherDeclaredWet    bool
	WindVelMS             float32
	PlayerCarIdx          int32
	PlayerPosition        int32
	PlayerClassPosition   int32
	PlayerTireCompound    int32
	PitSvFlags            uint32
	PitSvFuelL            float32
	PitSvCompound         int32

	// 64-car live grid telemetry (fixed stack arrays, zero GC pointers)
	HasGridArrays       bool
	CarIdxLapDistPct    [MaxSimCars]float32
	CarIdxEstTime       [MaxSimCars]float32
	CarIdxClassPosition [MaxSimCars]int32
	CarIdxTrackSurface  [MaxSimCars]int32
	CarIdxOnPitRoad     [MaxSimCars]bool
	CarIdxTireCompound  [MaxSimCars]int32
	CarIdxLastLapTime   [MaxSimCars]float32
	CarIdxBestLapTime   [MaxSimCars]float32
	CarIdxLap           [MaxSimCars]int32
}

// SessionMetadata identifies the circuit, car, session types, and class grid extracted
// dynamically from the SessionInfo YAML block in shared memory.
type SessionMetadata struct {
	TrackSlug        string              `json:"track_slug"`
	TrackName        string              `json:"track_name"`
	TrackLengthKm    float64             `json:"track_length_km"`
	CarName          string              `json:"car_name"`
	SessionID        int                 `json:"session_id"`
	SubSessionID     int                 `json:"sub_session_id"`
	SectorSplits     []float32           `json:"sector_splits,omitempty"`
	DriverCarIdx     int                 `json:"driver_car_idx,omitempty"`
	IncidentLimit    int                 `json:"incident_limit,omitempty"`
	SessionTypes     [12]string          `json:"-"`
	CarClassIDs      [MaxSimCars]int     `json:"-"`
	CarClassRelSpeed [MaxSimCars]int     `json:"-"`
	CarIsSpectator   [MaxSimCars]bool    `json:"-"`
}

// SessionTypeFor resolves normalized session type ("practice", "qualify", "race") for a SessionNum.
func (m SessionMetadata) SessionTypeFor(sessionNum int32) string {
	if sessionNum >= 0 && int(sessionNum) < len(m.SessionTypes) {
		if st := m.SessionTypes[sessionNum]; st != "" {
			return st
		}
	}
	for _, st := range m.SessionTypes {
		if st != "" {
			return st
		}
	}
	return "practice"
}

// CompletedLap is the payload dispatched asynchronously via HTTP POST
// whenever the driver crosses the start/finish line.
type CompletedLap struct {
	UserID        string       `json:"user_id"`
	TrackSlug     string       `json:"track_slug"`
	TrackName     string       `json:"track_name"`
	TrackLengthKm float64      `json:"track_length_km"`
	CarName       string       `json:"car_name"`
	SessionID     int          `json:"session_id"`
	SubSessionID  int          `json:"sub_session_id"`
	LapNumber     int32        `json:"lap_number"`
	LapTimeMs     uint32       `json:"lap_time_ms"`
	IsClean       bool         `json:"is_clean"`
	IncidentDelta int32        `json:"incident_delta"`
	TrackTempC    float32      `json:"track_temp_c"`
	AirTempC      float32      `json:"air_temp_c"`
	IsWet         bool         `json:"is_wet"`
	FuelLevelL    float32      `json:"fuel_level_l,omitempty"`
	FuelUsedL     float32      `json:"fuel_used_l,omitempty"`
	SectorSplits  []float32    `json:"sector_splits,omitempty"`
	SampleRateHz  int          `json:"sample_rate_hz"`
	Race          *RaceContext `json:"race_context,omitempty"`
	Samples       []Sample     `json:"samples"`
}

// CompletedSector is dispatched asynchronously as soon as the car crosses an official
// intermediate sector split, enabling mid-lap coaching and optimal-lap tracking.
type CompletedSector struct {
	UserID         string       `json:"user_id"`
	TrackSlug      string       `json:"track_slug"`
	TrackName      string       `json:"track_name"`
	TrackLengthKm  float64      `json:"track_length_km"`
	CarName        string       `json:"car_name"`
	SessionID      int          `json:"session_id"`
	SubSessionID   int          `json:"sub_session_id"`
	LapNumber      int32        `json:"lap_number"`
	SectorNumber   int          `json:"sector_number"`
	SectorStartPct float32      `json:"sector_start_pct"`
	SectorEndPct   float32      `json:"sector_end_pct"`
	SectorTimeMs   uint32       `json:"sector_time_ms"`
	IsClean        bool         `json:"is_clean"`
	TrackTempC     float32      `json:"track_temp_c"`
	AirTempC       float32      `json:"air_temp_c"`
	IsWet          bool         `json:"is_wet"`
	SectorSplits   []float32    `json:"sector_splits,omitempty"`
	Race           *RaceContext `json:"race_context,omitempty"`
	Samples        []Sample     `json:"samples"`
}

// Source abstracts telemetry acquisition so the exact same 20 Hz engine runs
// against native Windows shared memory (syscall) and the Linux track simulator.
type Source interface {
	Connect(ctx context.Context) error
	Close() error
	ReadFrame(out *RawFrame) (ok bool, err error)
	SessionMetadata() SessionMetadata
}
