package irsdk

import (
	"bytes"
	"encoding/binary"
	"errors"
	"fmt"
	"math"
	"strconv"
	"strings"

	"github.com/pacefriend/client/internal/telemetry"
)

// Official iRacing SDK binary layout constants (irsdk_defines.h).
const (
	HeaderSize        = 112
	VarBufSize        = 16
	MaxBufs           = 4
	VarHeaderSize     = 144
	MaxStringLen      = 32
	MaxDescLen        = 64
	StatusConnected   = 0x00000001
	TrackSurfaceOff   = 0
	MaxTornReadChecks = 3
)

// Shared memory mapping names on Windows.
// SpecShmName honors the PaceFriend technical specification ("Local\IRacingData"),
// while OfficialShmName provides seamless compatibility with retail iRacing builds.
const (
	SpecShmName     = `Local\IRacingData`
	OfficialShmName = `Local\IRacingMemMapFileName`
)

// VarType mirrors irsdk_VarType in irsdk_defines.h.
type VarType int32

const (
	VarTypeChar     VarType = 0
	VarTypeBool     VarType = 1
	VarTypeInt      VarType = 2
	VarTypeBitField VarType = 3
	VarTypeFloat    VarType = 4
	VarTypeDouble   VarType = 5
)

func (vt VarType) byteWidth() int {
	switch vt {
	case VarTypeChar, VarTypeBool:
		return 1
	case VarTypeInt, VarTypeBitField, VarTypeFloat:
		return 4
	case VarTypeDouble:
		return 8
	default:
		return 0
	}
}

var (
	ErrMemoryTooSmall     = errors.New("irsdk: shared memory region smaller than header")
	ErrSimDisconnected    = errors.New("irsdk: simulator status is not connected")
	ErrCorruptHeader      = errors.New("irsdk: header offsets exceed mapped memory bounds")
	ErrMissingRequiredVar = errors.New("irsdk: missing required telemetry variable in SDK header")
	ErrUnsupportedOS      = errors.New("irsdk: native Windows shared memory requires GOOS=windows (use -sim on non-Windows)")
)

// VarBufDescriptor represents one of the up to 4 rolling telemetry buffers.
type VarBufDescriptor struct {
	TickCount int32
	BufOffset int32
}

// Header mirrors irsdk_header (112 bytes, little-endian).
type Header struct {
	Ver               int32
	Status            int32
	TickRate          int32
	SessionInfoUpdate int32
	SessionInfoLen    int32
	SessionInfoOffset int32
	NumVars           int32
	VarHeaderOffset   int32
	NumBuf            int32
	BufLen            int32
	VarBuf            [MaxBufs]VarBufDescriptor
}

// ParseHeader decodes the 112-byte irsdk_header at offset 0 without heap allocations.
func ParseHeader(mem []byte, out *Header) error {
	if len(mem) < HeaderSize {
		return ErrMemoryTooSmall
	}
	out.Ver = int32(binary.LittleEndian.Uint32(mem[0:4]))
	out.Status = int32(binary.LittleEndian.Uint32(mem[4:8]))
	out.TickRate = int32(binary.LittleEndian.Uint32(mem[8:12]))
	out.SessionInfoUpdate = int32(binary.LittleEndian.Uint32(mem[12:16]))
	out.SessionInfoLen = int32(binary.LittleEndian.Uint32(mem[16:20]))
	out.SessionInfoOffset = int32(binary.LittleEndian.Uint32(mem[20:24]))
	out.NumVars = int32(binary.LittleEndian.Uint32(mem[24:28]))
	out.VarHeaderOffset = int32(binary.LittleEndian.Uint32(mem[28:32]))
	out.NumBuf = int32(binary.LittleEndian.Uint32(mem[32:36]))
	out.BufLen = int32(binary.LittleEndian.Uint32(mem[36:40]))
	// Offsets 40..47 are pad1[2]. VarBuf array starts at byte 48.
	for i := 0; i < MaxBufs; i++ {
		base := 48 + i*VarBufSize
		out.VarBuf[i].TickCount = int32(binary.LittleEndian.Uint32(mem[base : base+4]))
		out.VarBuf[i].BufOffset = int32(binary.LittleEndian.Uint32(mem[base+4 : base+8]))
	}
	return nil
}

type varSlot struct {
	offset  int
	varType VarType
	present bool
}

// VarIndex caches dynamically resolved byte offsets for O(1) zero-allocation reads.
type VarIndex struct {
	BufLen int

	// Required core PaceFriend variables
	Speed      varSlot
	Brake      varSlot
	Throttle   varSlot
	Lap        varSlot
	LapDistPct varSlot
	Gear       varSlot

	// Optional enrichment variables for exact lap timing, cleanliness & weather matching
	SessionTime              varSlot
	LapLastLapTime           varSlot
	PlayerTrackSurface       varSlot
	OnPitRoad                varSlot
	PlayerCarMyIncidentCount varSlot
	TrackTempCrew            varSlot
	TrackTemp                varSlot
	AirTemp                  varSlot
	TrackWetness             varSlot
	WeatherDeclaredWet       varSlot
}

// IndexVariables parses the irsdk_varHeader table and resolves byte offsets for all
// required and optional telemetry channels, validating buffer bounds upfront.
func IndexVariables(mem []byte, hdr *Header) (*VarIndex, error) {
	if hdr.NumVars <= 0 || hdr.VarHeaderOffset < HeaderSize || hdr.BufLen <= 0 {
		return nil, ErrCorruptHeader
	}
	tableEnd := int(hdr.VarHeaderOffset) + int(hdr.NumVars)*VarHeaderSize
	if tableEnd > len(mem) {
		return nil, ErrCorruptHeader
	}

	idx := &VarIndex{BufLen: int(hdr.BufLen)}
	bufLen := int(hdr.BufLen)

	for i := 0; i < int(hdr.NumVars); i++ {
		entry := mem[int(hdr.VarHeaderOffset)+i*VarHeaderSize : int(hdr.VarHeaderOffset)+(i+1)*VarHeaderSize]
		vType := VarType(binary.LittleEndian.Uint32(entry[0:4]))
		vOffset := int(int32(binary.LittleEndian.Uint32(entry[4:8])))
		width := vType.byteWidth()
		if width == 0 || vOffset < 0 || vOffset+width > bufLen {
			continue
		}

		name := cString(entry[16 : 16+MaxStringLen])
		slot := varSlot{offset: vOffset, varType: vType, present: true}

		switch name {
		case "Speed":
			idx.Speed = slot
		case "Brake":
			idx.Brake = slot
		case "Throttle":
			idx.Throttle = slot
		case "Lap":
			idx.Lap = slot
		case "LapDistPct":
			idx.LapDistPct = slot
		case "Gear":
			idx.Gear = slot
		case "SessionTime":
			idx.SessionTime = slot
		case "LapLastLapTime":
			idx.LapLastLapTime = slot
		case "PlayerTrackSurface":
			idx.PlayerTrackSurface = slot
		case "OnPitRoad":
			idx.OnPitRoad = slot
		case "PlayerCarMyIncidentCount":
			idx.PlayerCarMyIncidentCount = slot
		case "TrackTempCrew":
			idx.TrackTempCrew = slot
		case "TrackTemp":
			idx.TrackTemp = slot
		case "AirTemp":
			idx.AirTemp = slot
		case "TrackWetness":
			idx.TrackWetness = slot
		case "WeatherDeclaredWet":
			idx.WeatherDeclaredWet = slot
		}
	}

	missing := idx.missingRequired()
	if missing != "" {
		return nil, fmt.Errorf("%w: %s", ErrMissingRequiredVar, missing)
	}
	return idx, nil
}

func (idx *VarIndex) missingRequired() string {
	switch {
	case !idx.Speed.present:
		return "Speed"
	case !idx.Brake.present:
		return "Brake"
	case !idx.Throttle.present:
		return "Throttle"
	case !idx.Lap.present:
		return "Lap"
	case !idx.LapDistPct.present:
		return "LapDistPct"
	case !idx.Gear.present:
		return "Gear"
	default:
		return ""
	}
}

// ReadFrameFromMemory performs a lock-free triple-buffered read of the newest
// telemetry row in shared memory with torn-read verification and 0 heap allocations.
func ReadFrameFromMemory(mem []byte, idx *VarIndex, out *telemetry.RawFrame) (bool, error) {
	var hdr Header
	if err := ParseHeader(mem, &hdr); err != nil {
		return false, err
	}
	if hdr.Status&StatusConnected == 0 {
		return false, nil
	}

	numBuf := int(hdr.NumBuf)
	if numBuf <= 0 || numBuf > MaxBufs {
		return false, ErrCorruptHeader
	}

	for attempt := 0; attempt < MaxTornReadChecks; attempt++ {
		if attempt > 0 {
			if err := ParseHeader(mem, &hdr); err != nil {
				return false, err
			}
		}

		// Pick buffer slot with the highest tickCount.
		bestIdx := -1
		var bestTick int32 = -1
		for i := 0; i < numBuf; i++ {
			if hdr.VarBuf[i].TickCount > bestTick {
				bestTick = hdr.VarBuf[i].TickCount
				bestIdx = i
			}
		}
		if bestIdx < 0 || bestTick < 0 {
			return false, nil
		}

		bufOffset := int(hdr.VarBuf[bestIdx].BufOffset)
		if bufOffset < HeaderSize || bufOffset+idx.BufLen > len(mem) {
			return false, ErrCorruptHeader
		}
		row := mem[bufOffset : bufOffset+idx.BufLen]

		out.Sample.Speed = readFloat32(row, idx.Speed)
		out.Sample.Brake = readFloat32(row, idx.Brake)
		out.Sample.Throttle = readFloat32(row, idx.Throttle)
		out.Sample.Lap = readInt32(row, idx.Lap)
		out.Sample.LapDistPct = readFloat32(row, idx.LapDistPct)
		out.Sample.Gear = int16(readInt32(row, idx.Gear))

		var flags telemetry.SampleFlags
		if idx.PlayerTrackSurface.present && readInt32(row, idx.PlayerTrackSurface) == TrackSurfaceOff {
			flags |= telemetry.FlagOffTrack
		}
		if idx.OnPitRoad.present && readBool(row, idx.OnPitRoad) {
			flags |= telemetry.FlagOnPitRoad
		}
		out.Sample.Flags = flags

		if idx.SessionTime.present {
			out.SessionTimeSec = readFloat64(row, idx.SessionTime)
		} else {
			out.SessionTimeSec = 0
		}
		if idx.LapLastLapTime.present {
			out.LapLastLapTimeSec = readFloat32(row, idx.LapLastLapTime)
		} else {
			out.LapLastLapTimeSec = 0
		}
		if idx.PlayerCarMyIncidentCount.present {
			out.IncidentCount = readInt32(row, idx.PlayerCarMyIncidentCount)
		} else {
			out.IncidentCount = 0
		}

		// Track & ambient conditions for dynamic reference-lap matching
		switch {
		case idx.TrackTempCrew.present:
			out.TrackTempC = readFloat32(row, idx.TrackTempCrew)
		case idx.TrackTemp.present:
			out.TrackTempC = readFloat32(row, idx.TrackTemp)
		default:
			out.TrackTempC = 0
		}
		if idx.AirTemp.present {
			out.AirTempC = readFloat32(row, idx.AirTemp)
		} else {
			out.AirTempC = 0
		}
		// In irsdk_TrackWetness: 0=Unknown, 1=Dry, 2..7=MostlyDry..ExtremelyWet
		isWet := false
		if idx.TrackWetness.present && readInt32(row, idx.TrackWetness) >= 2 {
			isWet = true
		}
		if idx.WeatherDeclaredWet.present && readBool(row, idx.WeatherDeclaredWet) {
			isWet = true
		}
		out.IsWet = isWet

		// Re-read tickCount for this buffer slot to ensure iRacing did not overwrite
		// the buffer mid-read (lock-free optimistic concurrency check).
		tickPos := 48 + bestIdx*VarBufSize
		afterTick := int32(binary.LittleEndian.Uint32(mem[tickPos : tickPos+4]))
		if afterTick == bestTick {
			return true, nil
		}
	}

	// Extremely rare: 3 consecutive 60 Hz writes collided with our read; skip tick cleanly.
	return false, nil
}

func readFloat32(row []byte, s varSlot) float32 {
	switch s.varType {
	case VarTypeFloat:
		return math.Float32frombits(binary.LittleEndian.Uint32(row[s.offset : s.offset+4]))
	case VarTypeDouble:
		return float32(math.Float64frombits(binary.LittleEndian.Uint64(row[s.offset : s.offset+8])))
	case VarTypeInt, VarTypeBitField:
		return float32(int32(binary.LittleEndian.Uint32(row[s.offset : s.offset+4])))
	default:
		return 0
	}
}

func readFloat64(row []byte, s varSlot) float64 {
	switch s.varType {
	case VarTypeDouble:
		return math.Float64frombits(binary.LittleEndian.Uint64(row[s.offset : s.offset+8]))
	case VarTypeFloat:
		return float64(math.Float32frombits(binary.LittleEndian.Uint32(row[s.offset : s.offset+4])))
	default:
		return 0
	}
}

func readInt32(row []byte, s varSlot) int32 {
	switch s.varType {
	case VarTypeInt, VarTypeBitField:
		return int32(binary.LittleEndian.Uint32(row[s.offset : s.offset+4]))
	case VarTypeChar:
		return int32(int8(row[s.offset]))
	case VarTypeBool:
		if row[s.offset] != 0 {
			return 1
		}
		return 0
	default:
		return 0
	}
}

func readBool(row []byte, s varSlot) bool {
	return row[s.offset] != 0
}

func cString(b []byte) string {
	if idx := bytes.IndexByte(b, 0); idx >= 0 {
		return string(b[:idx])
	}
	return string(b)
}

// ParseSessionInfoYAML extracts circuit and player vehicle metadata from iRacing's
// ISO-8859-1 / ASCII SessionInfo YAML string without external dependencies.
func ParseSessionInfoYAML(raw []byte) telemetry.SessionMetadata {
	if idx := bytes.IndexByte(raw, 0); idx >= 0 {
		raw = raw[:idx]
	}

	var meta telemetry.SessionMetadata
	driverCarIdx := -1
	currentCarIdx := -2
	inDriversList := false

	lines := strings.Split(string(raw), "\n")
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			continue
		}

		// Track top-level section transitions
		if !strings.HasPrefix(line, " ") && !strings.HasPrefix(line, "\t") && strings.HasSuffix(trimmed, ":") {
			inDriversList = false
			currentCarIdx = -2
			continue
		}

		if trimmed == "Drivers:" {
			inDriversList = true
			currentCarIdx = -2
			continue
		}

		if inDriversList && strings.HasPrefix(trimmed, "- ") {
			currentCarIdx = -2
			trimmed = strings.TrimSpace(strings.TrimPrefix(trimmed, "- "))
		}

		colonIdx := strings.IndexByte(trimmed, ':')
		if colonIdx <= 0 {
			continue
		}
		key := strings.TrimSpace(trimmed[:colonIdx])
		val := strings.Trim(strings.TrimSpace(trimmed[colonIdx+1:]), `"'`)

		if !inDriversList {
			switch key {
			case "TrackName":
				meta.TrackSlug = slugify(val)
			case "TrackDisplayName":
				meta.TrackName = val
			case "TrackLength":
				// Format in iRacing YAML: "7.00 km"
				fields := strings.Fields(val)
				if len(fields) > 0 {
					if km, err := strconv.ParseFloat(fields[0], 64); err == nil {
						meta.TrackLengthKm = km
					}
				}
			case "SessionID":
				if v, err := strconv.Atoi(val); err == nil {
					meta.SessionID = v
				}
			case "SubSessionID":
				if v, err := strconv.Atoi(val); err == nil {
					meta.SubSessionID = v
				}
			case "DriverCarIdx":
				if v, err := strconv.Atoi(val); err == nil {
					driverCarIdx = v
				}
			}
			continue
		}

		// Inside DriverInfo -> Drivers list
		switch key {
		case "CarIdx":
			if v, err := strconv.Atoi(val); err == nil {
				currentCarIdx = v
			}
		case "CarScreenName":
			if driverCarIdx >= 0 && currentCarIdx == driverCarIdx {
				meta.CarName = val
			}
		case "CarPath":
			if driverCarIdx >= 0 && currentCarIdx == driverCarIdx && meta.CarName == "" {
				meta.CarName = val
			}
		}
	}

	if meta.TrackName == "" && meta.TrackSlug != "" {
		meta.TrackName = meta.TrackSlug
	}
	if meta.TrackSlug == "" && meta.TrackName != "" {
		meta.TrackSlug = slugify(meta.TrackName)
	}
	return meta
}

func slugify(s string) string {
	s = strings.ToLower(strings.TrimSpace(s))
	var b strings.Builder
	b.Grow(len(s))
	prevDash := false
	for i := 0; i < len(s); i++ {
		c := s[i]
		if (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') {
			b.WriteByte(c)
			prevDash = false
		} else if !prevDash && b.Len() > 0 {
			b.WriteByte('-')
			prevDash = true
		}
	}
	return strings.TrimSuffix(b.String(), "-")
}
