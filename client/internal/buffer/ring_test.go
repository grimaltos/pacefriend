package buffer

import (
	"testing"

	"github.com/pacefriend/client/internal/telemetry"
)

func TestRingBufferNormalLap(t *testing.T) {
	ring := NewRingBuffer(16)
	pool := NewSnapshotPool(16)

	for i := 0; i < 10; i++ {
		ring.Push(telemetry.Sample{
			TimestampMs: uint32(i * 50),
			LapDistPct:  float32(i) * 0.1,
			Lap:         1,
		})
	}

	if ring.Len() != 10 {
		t.Fatalf("expected Len() = 10, got %d", ring.Len())
	}

	batch := ring.SnapshotAndReset(pool)
	if batch == nil {
		t.Fatal("expected non-nil batch")
	}
	defer pool.Release(batch)

	if ring.Len() != 0 {
		t.Fatalf("expected Len() = 0 after SnapshotAndReset, got %d", ring.Len())
	}
	if len(batch.Samples) != 10 {
		t.Fatalf("expected 10 snapshot samples, got %d", len(batch.Samples))
	}
	for i, s := range batch.Samples {
		wantTs := uint32(i * 50)
		if s.TimestampMs != wantTs {
			t.Fatalf("sample[%d]: expected TimestampMs=%d, got %d", i, wantTs, s.TimestampMs)
		}
	}
}

func TestRingBufferWrapAroundChronologicalOrder(t *testing.T) {
	ring := NewRingBuffer(8)
	pool := NewSnapshotPool(8)

	// Push 13 samples into capacity 8; oldest 5 (0..4) are overwritten,
	// leaving samples 5..12 in chronological order.
	for i := 0; i < 13; i++ {
		ring.Push(telemetry.Sample{
			TimestampMs: uint32(i * 50),
			Speed:       float32(50 + i),
		})
	}

	if ring.Len() != 8 {
		t.Fatalf("expected capped Len() = 8, got %d", ring.Len())
	}

	batch := ring.SnapshotAndReset(pool)
	defer pool.Release(batch)

	if len(batch.Samples) != 8 {
		t.Fatalf("expected 8 samples, got %d", len(batch.Samples))
	}
	for idx, s := range batch.Samples {
		wantTs := uint32((idx + 5) * 50)
		if s.TimestampMs != wantTs {
			t.Fatalf("wrapped sample[%d]: expected TimestampMs=%d, got %d", idx, wantTs, s.TimestampMs)
		}
	}
}

func TestZeroAllocsSteadyState(t *testing.T) {
	ring := NewRingBuffer(2048)
	pool := NewSnapshotPool(2048)

	// Warm up the pool once.
	warm := pool.Acquire(1800)
	pool.Release(warm)

	sample := telemetry.Sample{
		TimestampMs: 50,
		LapDistPct:  0.25,
		Speed:       62.5,
		Throttle:    1.0,
		Lap:         3,
		Gear:        5,
	}

	pushAllocs := testing.AllocsPerRun(1000, func() {
		ring.Push(sample)
	})
	if pushAllocs != 0 {
		t.Fatalf("expected 0 allocs on RingBuffer.Push, got %f", pushAllocs)
	}

	ring.Reset()
	snapAllocs := testing.AllocsPerRun(200, func() {
		for i := 0; i < 1800; i++ {
			ring.Push(sample)
		}
		batch := ring.SnapshotAndReset(pool)
		pool.Release(batch)
	})
	if snapAllocs != 0 {
		t.Fatalf("expected 0 allocs on full lap SnapshotAndReset + Release, got %f", snapAllocs)
	}
}

func BenchmarkRingBufferPush(b *testing.B) {
	ring := NewRingBuffer(DefaultCapacity)
	s := telemetry.Sample{TimestampMs: 50, Speed: 70.2, Throttle: 1.0, Lap: 2, Gear: 6}
	b.ReportAllocs()
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		ring.Push(s)
	}
}

func BenchmarkRingBufferLapSnapshot(b *testing.B) {
	ring := NewRingBuffer(DefaultCapacity)
	pool := NewSnapshotPool(2400)
	warm := pool.Acquire(2400)
	pool.Release(warm)

	s := telemetry.Sample{TimestampMs: 50, Speed: 70.2, Throttle: 1.0, Lap: 2, Gear: 6}
	b.ReportAllocs()
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		for j := 0; j < 2400; j++ {
			ring.Push(s)
		}
		batch := ring.SnapshotAndReset(pool)
		pool.Release(batch)
	}
}
