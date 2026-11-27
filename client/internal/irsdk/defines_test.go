package irsdk

import (
	"context"
	"encoding/binary"
	"errors"
	"math"
	"testing"

	"github.com/pacefriend/client/internal/telemetry"
)

func TestHeaderAndDynamicVarIndexing(t *testing.T) {
	if OfficialShmName != `Local\IRSDKMemMapFileName` {
		t.Fatalf("OfficialShmName must match IRSDK_MEMMAPFILENAME, got %q", OfficialShmName)
	}

	mem := buildSyntheticShmBlock(simSessionYAML)

	var hdr Header
	if err := ParseHeader(mem, &hdr); err != nil {
		t.Fatalf("ParseHeader failed: %v", err)
	}
	if hdr.Ver != 2 || hdr.NumBuf != 3 || hdr.TickRate != 60 {
		t.Fatalf("unexpected header values: %+v", hdr)
	}
	if req := RequiredMemorySize(&hdr); req != len(mem) {
		t.Fatalf("RequiredMemorySize=%d, expected %d", req, len(mem))
	}

	idx, err := IndexVariables(mem, &hdr)
	if err != nil {
		t.Fatalf("IndexVariables failed: %v", err)
	}

	// Write distinct values into buffer[1] with highest tickCount (42)
	// and stale values into buffer[0] (tickCount 41) & buffer[2] (tickCount 40).
	setBufTick := func(slot int, tick int32, speed float32, lap int32, pct float32, gear int32) {
		base := 48 + slot*VarBufSize
		binary.LittleEndian.PutUint32(mem[base:base+4], uint32(tick))
		bufOff := int(binary.LittleEndian.Uint32(mem[base+4 : base+8]))
		row := mem[bufOff : bufOff+simRowBufLen]
		binary.LittleEndian.PutUint32(row[0:4], math.Float32bits(speed))
		binary.LittleEndian.PutUint32(row[4:8], math.Float32bits(0.75))  // Brake
		binary.LittleEndian.PutUint32(row[8:12], math.Float32bits(0.10)) // Throttle
		binary.LittleEndian.PutUint32(row[12:16], uint32(lap))
		binary.LittleEndian.PutUint32(row[16:20], math.Float32bits(pct))
		binary.LittleEndian.PutUint32(row[20:24], uint32(gear))
		binary.LittleEndian.PutUint32(row[36:40], TrackSurfaceOff) // Off-track
	}

	setBufTick(0, 41, 30.0, 2, 0.10, 2)
	setBufTick(1, 42, 68.5, 3, 0.55, 5)
	setBufTick(2, 40, 20.0, 2, 0.05, 1)

	var frame telemetry.RawFrame
	ok, err := ReadFrameFromMemory(mem, idx, &frame)
	if err != nil || !ok {
		t.Fatalf("ReadFrameFromMemory expected ok=true, err=nil; got ok=%v err=%v", ok, err)
	}

	if frame.Sample.Speed != 68.5 || frame.Sample.Lap != 3 || frame.Sample.Gear != 5 {
		t.Fatalf("expected newest buffer[1] values, got %+v", frame.Sample)
	}
	if frame.Sample.Flags&telemetry.FlagOffTrack == 0 {
		t.Fatalf("expected FlagOffTrack to be set, got flags=%d", frame.Sample.Flags)
	}
}

func TestMissingRequiredVariableDetection(t *testing.T) {
	mem := buildSyntheticShmBlock(simSessionYAML)
	var hdr Header
	_ = ParseHeader(mem, &hdr)

	// Corrupt variable name "Brake" -> "Bogus" in varHeader[1]
	entry := mem[int(hdr.VarHeaderOffset)+1*VarHeaderSize : int(hdr.VarHeaderOffset)+2*VarHeaderSize]
	copy(entry[16:32], "Bogus\x00")

	_, err := IndexVariables(mem, &hdr)
	if !errors.Is(err, ErrMissingRequiredVar) {
		t.Fatalf("expected ErrMissingRequiredVar, got %v", err)
	}
}

func TestParseSessionInfoYAML(t *testing.T) {
	meta := ParseSessionInfoYAML([]byte(simSessionYAML))
	if meta.TrackSlug != "spa-up" {
		t.Fatalf("expected TrackSlug 'spa-up', got %q", meta.TrackSlug)
	}
	if meta.TrackName != "Circuit de Spa-Francorchamps" {
		t.Fatalf("expected TrackName 'Circuit de Spa-Francorchamps', got %q", meta.TrackName)
	}
	if meta.TrackLengthKm != 7.00 {
		t.Fatalf("expected TrackLengthKm 7.00, got %v", meta.TrackLengthKm)
	}
	if meta.CarName != "Porsche 911 GT3 R (992)" {
		t.Fatalf("expected player CarName 'Porsche 911 GT3 R (992)', got %q", meta.CarName)
	}
	if meta.SessionID != 104521 || meta.SubSessionID != 68912044 {
		t.Fatalf("unexpected session IDs: %+v", meta)
	}
}

func TestReadFrameZeroAllocs(t *testing.T) {
	sim := NewSimulatorSource(SimulatorConfig{Speedup: 5.0})
	if err := sim.Connect(context.Background()); err != nil {
		t.Fatalf("sim.Connect failed: %v", err)
	}
	defer sim.Close()

	var hdr Header
	_ = ParseHeader(sim.mem, &hdr)
	var frame telemetry.RawFrame

	allocs := testing.AllocsPerRun(1000, func() {
		_, _ = ReadFrameFromMemory(sim.mem, sim.varIndex, &frame)
	})
	if allocs != 0 {
		t.Fatalf("expected 0 heap allocations in ReadFrameFromMemory, got %f", allocs)
	}
}

func BenchmarkReadFrameFromMemory(b *testing.B) {
	sim := NewSimulatorSource(SimulatorConfig{Speedup: 1.0})
	_ = sim.Connect(context.Background())
	defer sim.Close()

	var frame telemetry.RawFrame
	b.ReportAllocs()
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		_, _ = ReadFrameFromMemory(sim.mem, sim.varIndex, &frame)
	}
}
