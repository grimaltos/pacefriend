package buffer

import (
	"sync"

	"github.com/pacefriend/client/internal/telemetry"
)

// DefaultCapacity holds up to ~13.6 minutes of 20 Hz telemetry (~458 KB of noscan RAM),
// comfortably covering even the Nürburgring VLN/Nordschleife without wrapping.
const DefaultCapacity = 16384

// SampleBatch wraps a reusable slice so sync.Pool stores a pointer directly
// without boxing a slice header onto the heap (0 B/op, 0 allocs/op).
type SampleBatch struct {
	Samples []telemetry.Sample
}

// SnapshotPool recycles SampleBatch buffers across lap uploads so cloning a
// completed lap for background HTTP transmission causes zero GC pressure.
type SnapshotPool struct {
	pool        sync.Pool
	defaultSize int
}

// NewSnapshotPool creates a pool pre-configured for typical lap lengths.
func NewSnapshotPool(defaultLapSamples int) *SnapshotPool {
	if defaultLapSamples <= 0 {
		defaultLapSamples = 4096 // ~3.4 minutes at 20 Hz
	}
	p := &SnapshotPool{defaultSize: defaultLapSamples}
	p.pool.New = func() any {
		return &SampleBatch{
			Samples: make([]telemetry.Sample, 0, p.defaultSize),
		}
	}
	return p
}

// Acquire returns a SampleBatch sized to length n.
func (p *SnapshotPool) Acquire(n int) *SampleBatch {
	b := p.pool.Get().(*SampleBatch)
	if cap(b.Samples) < n {
		b.Samples = make([]telemetry.Sample, n)
	} else {
		b.Samples = b.Samples[:n]
	}
	return b
}

// Release resets the slice length and returns the batch to the pool.
func (p *SnapshotPool) Release(b *SampleBatch) {
	if b == nil {
		return
	}
	b.Samples = b.Samples[:0]
	p.pool.Put(b)
}

// RingBuffer is a fixed-capacity, single-producer circular buffer optimized
// for the 20 Hz telemetry sampling loop (zero heap allocations).
type RingBuffer struct {
	buf   []telemetry.Sample
	head  int
	count int
}

// NewRingBuffer allocates the backing array once at startup.
func NewRingBuffer(capacity int) *RingBuffer {
	if capacity <= 0 {
		capacity = DefaultCapacity
	}
	return &RingBuffer{
		buf: make([]telemetry.Sample, capacity),
	}
}

// Push writes a sample at the write cursor, overwriting the oldest entry only
// if the active lap exceeds buffer capacity. Zero heap allocations.
func (r *RingBuffer) Push(s telemetry.Sample) {
	r.buf[r.head] = s
	r.head++
	if r.head == len(r.buf) {
		r.head = 0
	}
	if r.count < len(r.buf) {
		r.count++
	}
}

// Len returns the number of samples currently stored for the active lap.
func (r *RingBuffer) Len() int {
	return r.count
}

// Cap returns the fixed capacity of the circular buffer.
func (r *RingBuffer) Cap() int {
	return len(r.buf)
}

// Reset discards the active lap samples in O(1) without touching backing memory.
func (r *RingBuffer) Reset() {
	r.head = 0
	r.count = 0
}

// SnapshotFrom copies samples from logical index fromIdx (0 = oldest sample of the
// active lap) up to the newest sample into a pooled SampleBatch WITHOUT resetting
// the buffer. Used to upload intermediate sectors mid-lap. Returns nil if the range
// is empty or the buffer already overwrote the requested start.
func (r *RingBuffer) SnapshotFrom(pool *SnapshotPool, fromIdx int) *SampleBatch {
	if fromIdx < 0 || fromIdx >= r.count || r.count >= len(r.buf) {
		return nil
	}
	n := r.count - fromIdx
	batch := pool.Acquire(n)
	start := r.head - r.count
	if start < 0 {
		start += len(r.buf)
	}
	pos := start + fromIdx
	if pos >= len(r.buf) {
		pos -= len(r.buf)
	}
	firstChunk := len(r.buf) - pos
	if firstChunk >= n {
		copy(batch.Samples, r.buf[pos:pos+n])
	} else {
		copy(batch.Samples, r.buf[pos:])
		copy(batch.Samples[firstChunk:], r.buf[:n-firstChunk])
	}
	return batch
}

// SnapshotAndReset copies the active lap samples in chronological order into a
// pooled SampleBatch and resets the ring buffer for the new lap.
func (r *RingBuffer) SnapshotAndReset(pool *SnapshotPool) *SampleBatch {
	if r.count == 0 {
		r.head = 0
		return nil
	}

	batch := pool.Acquire(r.count)
	dst := batch.Samples

	// Fast path: buffer did not wrap around during this lap.
	if r.head == r.count {
		copy(dst, r.buf[:r.count])
		r.head = 0
		r.count = 0
		return batch
	}

	// Wrapped path: reconstruct chronological sequence from oldest to newest.
	start := r.head - r.count
	if start < 0 {
		start += len(r.buf)
	}
	firstChunk := len(r.buf) - start
	if firstChunk >= r.count {
		copy(dst, r.buf[start:start+r.count])
	} else {
		copy(dst, r.buf[start:])
		copy(dst[firstChunk:], r.buf[:r.count-firstChunk])
	}

	r.head = 0
	r.count = 0
	return batch
}
