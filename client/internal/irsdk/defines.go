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
	GravityMS2        = 9.80665
)

// Shared memory mapping names on Windows.
// OfficialShmName matches IRSDK_MEMMAPFILENAME ("Local\IRSDKMemMapFileName") in irsdk_defines.h.
// LegacyShmName and SpecShmName are kept as automatic fallbacks for custom/spec harnesses.
const (
	OfficialShmName = `Local\IRSDKMemMapFileName`
	LegacyShmName   = `Local\IRacingMemMapFileName`
	SpecShmName     = `Local\IRacingData`
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

// RequiredMemorySize computes the exact byte span needed to address the header,
// SessionInfo YAML block, variable descriptor table, and all rolling telemetry buffers.
func RequiredMemorySize(hdr *Header) int {
	maxEnd := HeaderSize
	if hdr.SessionInfoOffset >= HeaderSize && hdr.SessionInfoLen > 0 {
		if end := int(hdr.SessionInfoOffset) + int(hdr.SessionInfoLen); end > maxEnd {
			maxEnd = end
		}
	}
	if hdr.VarHeaderOffset >= HeaderSize && hdr.NumVars > 0 {
		if end := int(hdr.VarHeaderOffset) + int(hdr.NumVars)*VarHeaderSize; end > maxEnd {
			maxEnd = end
		}
	}
	numBuf := int(hdr.NumBuf)
	if numBuf > MaxBufs {
		numBuf = MaxBufs
	}
	if hdr.BufLen > 0 {
		for i := 0; i < numBuf; i++ {
			if hdr.VarBuf[i].BufOffset >= HeaderSize {
				if end := int(hdr.VarBuf[i].BufOffset) + int(hdr.BufLen); end > maxEnd {
					maxEnd = end
				}
			}
		}
	}
	return maxEnd
}

type varSlot struct {
	offset  int
	varType VarType
	count   int
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

	// Racing line & chassis rotation channels (64-bit world Lat/Lon + steering/yaw + ABS/kerbs)
	Lat                varSlot
	Lon                varSlot
	SteeringWheelAngle varSlot
	YawRate            varSlot
	LatAccel           varSlot
	LongAccel          varSlot
	BrakeABSactive     varSlot
	TireLFRumblePitch  varSlot
	TireRFRumblePitch  varSlot

	// Optional enrichment variables for exact lap timing, cleanliness & weather matching
	SessionTime              varSlot
	LapLastLapTime           varSlot
	PlayerTrackSurface       varSlot
	OnPitRoad                varSlot
	IsOnTrack                varSlot
	PlayerCarMyIncidentCount varSlot
	PlayerCarTeamIncidents   varSlot
	TrackTempCrew            varSlot
	TrackTemp                varSlot
	AirTemp                  varSlot
	TrackWetness             varSlot
	WeatherDeclaredWet       varSlot
	Precipitation            varSlot
	WindVel                  varSlot
	FuelLevel                varSlot

	// Session clock, flags, player position & Black Box pit-service channels
	SessionNum            varSlot
	SessionState          varSlot
	SessionFlags          varSlot
	SessionTimeRemain     varSlot
	SessionLapsRemainEx   varSlot
	SessionLapsRemain     varSlot
	PlayerCarIdx          varSlot
	PlayerCarPosition     varSlot
	PlayerCarClassPos     varSlot
	PlayerTireCompound    varSlot
	PitSvFlags            varSlot
	PitSvFuel             varSlot
	PitSvTireCompound     varSlot

	// 64-car live grid telemetry arrays (for race combat mute, rival pace, crossover & hazard radar)
	CarIdxLapDistPct    varSlot
	CarIdxEstTime       varSlot
	CarIdxClassPosition varSlot
	CarIdxTrackSurface  varSlot
	CarIdxOnPitRoad     varSlot
	CarIdxTireCompound  varSlot
	CarIdxLastLapTime   varSlot
	CarIdxBestLapTime   varSlot
	CarIdxLap           varSlot

	// Pit-box / garage tyre pyrometer channels (unlocked by iRacing upon stopping in pits/garage)
	LFtempCL varSlot
	LFtempCM varSlot
	LFtempCR varSlot
	LFtempM  varSlot
	RFtempCL varSlot
	RFtempCM varSlot
	RFtempCR varSlot
	RFtempM  varSlot
	LRtempCL varSlot
	LRtempCM varSlot
	LRtempCR varSlot
	LRtempM  varSlot
	RRtempCL varSlot
	RRtempCM varSlot
	RRtempCR varSlot
	RRtempM  varSlot
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
		vCount := int(int32(binary.LittleEndian.Uint32(entry[8:12])))
		if vCount <= 0 {
			vCount = 1
		}
		width := vType.byteWidth()
		if width == 0 || vOffset < 0 || vOffset+(width*vCount) > bufLen {
			continue
		}

		name := cString(entry[16 : 16+MaxStringLen])
		slot := varSlot{offset: vOffset, varType: vType, count: vCount, present: true}

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
		case "Lat":
			idx.Lat = slot
		case "Lon":
			idx.Lon = slot
		case "SteeringWheelAngle":
			idx.SteeringWheelAngle = slot
		case "YawRate":
			idx.YawRate = slot
		case "LatAccel":
			idx.LatAccel = slot
		case "LongAccel":
			idx.LongAccel = slot
		case "BrakeABSactive":
			idx.BrakeABSactive = slot
		case "TireLF_RumblePitch":
			idx.TireLFRumblePitch = slot
		case "TireRF_RumblePitch":
			idx.TireRFRumblePitch = slot
		case "SessionTime":
			idx.SessionTime = slot
		case "LapLastLapTime":
			idx.LapLastLapTime = slot
		case "PlayerTrackSurface":
			idx.PlayerTrackSurface = slot
		case "OnPitRoad":
			idx.OnPitRoad = slot
		case "IsOnTrack":
			idx.IsOnTrack = slot
		case "PlayerCarMyIncidentCount":
			idx.PlayerCarMyIncidentCount = slot
		case "PlayerCarTeamIncidentCount":
			idx.PlayerCarTeamIncidents = slot
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
		case "Precipitation":
			idx.Precipitation = slot
		case "WindVel":
			idx.WindVel = slot
		case "FuelLevel":
			idx.FuelLevel = slot
		case "SessionNum":
			idx.SessionNum = slot
		case "SessionState":
			idx.SessionState = slot
		case "SessionFlags":
			idx.SessionFlags = slot
		case "SessionTimeRemain":
			idx.SessionTimeRemain = slot
		case "SessionLapsRemainEx":
			idx.SessionLapsRemainEx = slot
		case "SessionLapsRemain":
			idx.SessionLapsRemain = slot
		case "PlayerCarIdx":
			idx.PlayerCarIdx = slot
		case "PlayerCarPosition":
			idx.PlayerCarPosition = slot
		case "PlayerCarClassPosition":
			idx.PlayerCarClassPos = slot
		case "PlayerTireCompound":
			idx.PlayerTireCompound = slot
		case "PitSvFlags":
			idx.PitSvFlags = slot
		case "PitSvFuel":
			idx.PitSvFuel = slot
		case "PitSvTireCompound":
			idx.PitSvTireCompound = slot
		case "CarIdxLapDistPct":
			idx.CarIdxLapDistPct = slot
		case "CarIdxEstTime":
			idx.CarIdxEstTime = slot
		case "CarIdxClassPosition":
			idx.CarIdxClassPosition = slot
		case "CarIdxTrackSurface":
			idx.CarIdxTrackSurface = slot
		case "CarIdxOnPitRoad":
			idx.CarIdxOnPitRoad = slot
		case "CarIdxTireCompound":
			idx.CarIdxTireCompound = slot
		case "CarIdxLastLapTime":
			idx.CarIdxLastLapTime = slot
		case "CarIdxBestLapTime":
			idx.CarIdxBestLapTime = slot
		case "CarIdxLap":
			idx.CarIdxLap = slot
		case "LFtempCL":
			idx.LFtempCL = slot
		case "LFtempCM":
			idx.LFtempCM = slot
		case "LFtempCR":
			idx.LFtempCR = slot
		case "LFtempM":
			idx.LFtempM = slot
		case "RFtempCL":
			idx.RFtempCL = slot
		case "RFtempCM":
			idx.RFtempCM = slot
		case "RFtempCR":
			idx.RFtempCR = slot
		case "RFtempM":
			idx.RFtempM = slot
		case "LRtempCL":
			idx.LRtempCL = slot
		case "LRtempCM":
			idx.LRtempCM = slot
		case "LRtempCR":
			idx.LRtempCR = slot
		case "LRtempM":
			idx.LRtempM = slot
		case "RRtempCL":
			idx.RRtempCL = slot
		case "RRtempCM":
			idx.RRtempCM = slot
		case "RRtempCR":
			idx.RRtempCR = slot
		case "RRtempM":
			idx.RRtempM = slot
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

		if idx.Lat.present && idx.Lon.present {
			out.Sample.Lat = readFloat64(row, idx.Lat)
			out.Sample.Lon = readFloat64(row, idx.Lon)
		} else {
			out.Sample.Lat = 0
			out.Sample.Lon = 0
		}
		if idx.SteeringWheelAngle.present {
			out.Sample.Steer = readFloat32(row, idx.SteeringWheelAngle)
		} else {
			out.Sample.Steer = 0
		}
		if idx.YawRate.present {
			out.Sample.YawRate = readFloat32(row, idx.YawRate)
		} else {
			out.Sample.YawRate = 0
		}
		if idx.LatAccel.present {
			out.Sample.LatG = readFloat32(row, idx.LatAccel) / GravityMS2
		} else {
			out.Sample.LatG = 0
		}
		if idx.LongAccel.present {
			out.Sample.LongG = readFloat32(row, idx.LongAccel) / GravityMS2
		} else {
			out.Sample.LongG = 0
		}

		var flags telemetry.SampleFlags
		if idx.PlayerTrackSurface.present && readInt32(row, idx.PlayerTrackSurface) == TrackSurfaceOff {
			flags |= telemetry.FlagOffTrack
		}
		if idx.OnPitRoad.present && readBool(row, idx.OnPitRoad) {
			flags |= telemetry.FlagOnPitRoad
		}
		if idx.BrakeABSactive.present && readBool(row, idx.BrakeABSactive) {
			flags |= telemetry.FlagABSActive
		}
		if (idx.TireLFRumblePitch.present && readFloat32(row, idx.TireLFRumblePitch) > 1.0) ||
			(idx.TireRFRumblePitch.present && readFloat32(row, idx.TireRFRumblePitch) > 1.0) {
			flags |= telemetry.FlagCurbStrike
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
		out.TrackWetnessLevel = 0
		if idx.TrackWetness.present {
			rawW := readInt32(row, idx.TrackWetness)
			if rawW >= 2 {
				isWet = true
				out.TrackWetnessLevel = rawW - 1 // 1=MostlyDry .. 6=ExtremelyWet
			}
		}
		out.WeatherDeclaredWet = idx.WeatherDeclaredWet.present && readBool(row, idx.WeatherDeclaredWet)
		if out.WeatherDeclaredWet {
			isWet = true
		}
		if idx.Precipitation.present {
			out.Precipitation = readFloat32(row, idx.Precipitation)
			if out.Precipitation >= 0.08 {
				isWet = true
			}
		} else {
			out.Precipitation = 0
		}
		if idx.WindVel.present {
			out.WindVelMS = readFloat32(row, idx.WindVel)
		} else {
			out.WindVelMS = 0
		}
		out.IsWet = isWet

		if idx.PlayerCarTeamIncidents.present && out.IncidentCount == 0 {
			out.IncidentCount = readInt32(row, idx.PlayerCarTeamIncidents)
		}

		if idx.IsOnTrack.present {
			out.IsOnTrack = readBool(row, idx.IsOnTrack)
		} else {
			out.IsOnTrack = true
		}

		if idx.FuelLevel.present {
			out.FuelLevelL = readFloat32(row, idx.FuelLevel)
		} else {
			out.FuelLevelL = 0
		}

		// Session clock, race flags & Black Box pit-service state
		if idx.SessionNum.present {
			out.SessionNum = readInt32(row, idx.SessionNum)
		} else {
			out.SessionNum = 0
		}
		if idx.SessionState.present {
			out.SessionState = readInt32(row, idx.SessionState)
		} else {
			out.SessionState = 0
		}
		if idx.SessionFlags.present {
			out.SessionFlags = uint32(readInt32(row, idx.SessionFlags))
		} else {
			out.SessionFlags = 0
		}
		if idx.SessionTimeRemain.present {
			out.SessionTimeRemainSec = readFloat64(row, idx.SessionTimeRemain)
		} else {
			out.SessionTimeRemainSec = 0
		}
		switch {
		case idx.SessionLapsRemainEx.present:
			out.SessionLapsRemain = readInt32(row, idx.SessionLapsRemainEx)
		case idx.SessionLapsRemain.present:
			out.SessionLapsRemain = readInt32(row, idx.SessionLapsRemain)
		default:
			out.SessionLapsRemain = 0
		}
		if idx.PlayerCarIdx.present {
			out.PlayerCarIdx = readInt32(row, idx.PlayerCarIdx)
		} else {
			out.PlayerCarIdx = -1
		}
		if idx.PlayerCarPosition.present {
			out.PlayerPosition = readInt32(row, idx.PlayerCarPosition)
		} else {
			out.PlayerPosition = 0
		}
		if idx.PlayerCarClassPos.present {
			out.PlayerClassPosition = readInt32(row, idx.PlayerCarClassPos)
		} else {
			out.PlayerClassPosition = 0
		}
		if idx.PlayerTireCompound.present {
			out.PlayerTireCompound = readInt32(row, idx.PlayerTireCompound)
		} else {
			out.PlayerTireCompound = 0
		}
		if idx.PitSvFlags.present {
			out.PitSvFlags = uint32(readInt32(row, idx.PitSvFlags))
		} else {
			out.PitSvFlags = 0
		}
		if idx.PitSvFuel.present {
			out.PitSvFuelL = readFloat32(row, idx.PitSvFuel)
		} else {
			out.PitSvFuelL = 0
		}
		if idx.PitSvTireCompound.present {
			out.PitSvCompound = readInt32(row, idx.PitSvTireCompound)
		} else {
			out.PitSvCompound = 0
		}

		// 64-car live grid telemetry arrays (zero-allocation fixed-size stack copy)
		if idx.CarIdxLapDistPct.present {
			out.HasGridArrays = true
			readFloat32Array64(row, idx.CarIdxLapDistPct, &out.CarIdxLapDistPct)
			readFloat32Array64(row, idx.CarIdxEstTime, &out.CarIdxEstTime)
			readInt32Array64(row, idx.CarIdxClassPosition, &out.CarIdxClassPosition)
			readInt32Array64(row, idx.CarIdxTrackSurface, &out.CarIdxTrackSurface)
			readBoolArray64(row, idx.CarIdxOnPitRoad, &out.CarIdxOnPitRoad)
			readInt32Array64(row, idx.CarIdxTireCompound, &out.CarIdxTireCompound)
			readFloat32Array64(row, idx.CarIdxLastLapTime, &out.CarIdxLastLapTime)
			readFloat32Array64(row, idx.CarIdxBestLapTime, &out.CarIdxBestLapTime)
			readInt32Array64(row, idx.CarIdxLap, &out.CarIdxLap)
		} else {
			out.HasGridArrays = false
		}

		out.TyreLFTempC = readTyreTempC(row, idx.LFtempCL, idx.LFtempCM, idx.LFtempCR, idx.LFtempM)
		out.TyreRFTempC = readTyreTempC(row, idx.RFtempCL, idx.RFtempCM, idx.RFtempCR, idx.RFtempM)
		out.TyreLRTempC = readTyreTempC(row, idx.LRtempCL, idx.LRtempCM, idx.LRtempCR, idx.LRtempM)
		out.TyreRRTempC = readTyreTempC(row, idx.RRtempCL, idx.RRtempCM, idx.RRtempCR, idx.RRtempM)

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

func readFloat32Array64(row []byte, s varSlot, dst *[telemetry.MaxSimCars]float32) {
	if !s.present || s.varType != VarTypeFloat {
		*dst = [telemetry.MaxSimCars]float32{}
		return
	}
	n := s.count
	if n > telemetry.MaxSimCars {
		n = telemetry.MaxSimCars
	}
	for i := 0; i < n; i++ {
		off := s.offset + i*4
		dst[i] = math.Float32frombits(binary.LittleEndian.Uint32(row[off : off+4]))
	}
}

func readInt32Array64(row []byte, s varSlot, dst *[telemetry.MaxSimCars]int32) {
	if !s.present || (s.varType != VarTypeInt && s.varType != VarTypeBitField) {
		*dst = [telemetry.MaxSimCars]int32{}
		return
	}
	n := s.count
	if n > telemetry.MaxSimCars {
		n = telemetry.MaxSimCars
	}
	for i := 0; i < n; i++ {
		off := s.offset + i*4
		dst[i] = int32(binary.LittleEndian.Uint32(row[off : off+4]))
	}
}

func readBoolArray64(row []byte, s varSlot, dst *[telemetry.MaxSimCars]bool) {
	if !s.present {
		*dst = [telemetry.MaxSimCars]bool{}
		return
	}
	n := s.count
	if n > telemetry.MaxSimCars {
		n = telemetry.MaxSimCars
	}
	width := s.varType.byteWidth()
	if width <= 0 {
		return
	}
	for i := 0; i < n; i++ {
		dst[i] = row[s.offset+i*width] != 0
	}
}

func readTyreTempC(row []byte, cl, cm, cr, fallbackM varSlot) float32 {
	var sum float32
	var count int
	if cl.present {
		sum += readFloat32(row, cl)
		count++
	}
	if cm.present {
		sum += readFloat32(row, cm)
		count++
	}
	if cr.present {
		sum += readFloat32(row, cr)
		count++
	}
	if count > 0 {
		return sum / float32(count)
	}
	if fallbackM.present {
		return readFloat32(row, fallbackM)
	}
	return 0
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
	currentSessionNum := -1
	inDriversList := false
	inSectorsList := false
	inSessionsList := false

	lines := strings.Split(string(raw), "\n")
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			continue
		}

		// Track top-level section transitions
		if !strings.HasPrefix(line, " ") && !strings.HasPrefix(line, "\t") && strings.HasSuffix(trimmed, ":") {
			inDriversList = false
			inSectorsList = false
			inSessionsList = false
			currentCarIdx = -2
			currentSessionNum = -1
			continue
		}

		if trimmed == "Drivers:" {
			inDriversList = true
			inSectorsList = false
			inSessionsList = false
			currentCarIdx = -2
			continue
		}
		if trimmed == "Sectors:" {
			inSectorsList = true
			inDriversList = false
			inSessionsList = false
			continue
		}
		if trimmed == "Sessions:" {
			inSessionsList = true
			inDriversList = false
			inSectorsList = false
			currentSessionNum = -1
			continue
		}
		if inSessionsList && strings.HasSuffix(trimmed, ":") {
			// Nested list inside a Session (e.g. ResultsPositions:, ResultsFastestLap:) -> ignore nested items
			if trimmed != "Sessions:" {
				continue
			}
		}

		if (inDriversList || inSectorsList || inSessionsList) && strings.HasPrefix(trimmed, "- ") {
			if inDriversList {
				currentCarIdx = -2
			}
			trimmed = strings.TrimSpace(strings.TrimPrefix(trimmed, "- "))
		}

		colonIdx := strings.IndexByte(trimmed, ':')
		if colonIdx <= 0 {
			continue
		}
		key := strings.TrimSpace(trimmed[:colonIdx])
		val := strings.Trim(strings.TrimSpace(trimmed[colonIdx+1:]), `"'`)

		if inSectorsList {
			if key == "SectorStartPct" {
				if pct, err := strconv.ParseFloat(val, 32); err == nil && pct >= 0.0 && pct < 1.0 {
					meta.SectorSplits = append(meta.SectorSplits, float32(pct))
				}
			}
			continue
		}

		if inSessionsList {
			switch key {
			case "SessionNum":
				if v, err := strconv.Atoi(val); err == nil {
					currentSessionNum = v
				}
			case "SessionType":
				if currentSessionNum >= 0 && currentSessionNum < len(meta.SessionTypes) {
					meta.SessionTypes[currentSessionNum] = normalizeSessionType(val)
				}
			}
			continue
		}

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
			case "IncidentLimit":
				fields := strings.Fields(val)
				if len(fields) > 0 {
					if lim, err := strconv.Atoi(fields[0]); err == nil && lim > 0 {
						meta.IncidentLimit = lim
					}
				}
			case "DriverCarIdx":
				if v, err := strconv.Atoi(val); err == nil {
					driverCarIdx = v
					meta.DriverCarIdx = v
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
		case "CarClassID":
			if currentCarIdx >= 0 && currentCarIdx < telemetry.MaxSimCars {
				if v, err := strconv.Atoi(val); err == nil {
					meta.CarClassIDs[currentCarIdx] = v
				}
			}
		case "CarClassRelSpeed":
			if currentCarIdx >= 0 && currentCarIdx < telemetry.MaxSimCars {
				if v, err := strconv.Atoi(val); err == nil {
					meta.CarClassRelSpeed[currentCarIdx] = v
				}
			}
		case "IsSpectator":
			if currentCarIdx >= 0 && currentCarIdx < telemetry.MaxSimCars {
				meta.CarIsSpectator[currentCarIdx] = val == "1"
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

func normalizeSessionType(raw string) string {
	low := strings.ToLower(strings.TrimSpace(raw))
	switch {
	case strings.Contains(low, "race") || strings.Contains(low, "heat") || strings.Contains(low, "feature"):
		return "race"
	case strings.Contains(low, "qual"):
		return "qualify"
	default:
		return "practice"
	}
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
