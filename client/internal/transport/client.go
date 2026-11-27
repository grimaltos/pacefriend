package transport

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/pacefriend/client/internal/buffer"
	"github.com/pacefriend/client/internal/coaching"
	"github.com/pacefriend/client/internal/telemetry"
)

// LapResponse mirrors the JSON response returned by POST /api/v1/telemetry/lap.
type LapResponse struct {
	Status          string         `json:"status"`
	LapStored       bool           `json:"lap_stored"`
	CleanLapsWindow int            `json:"clean_laps_window"`
	CoachingPlan    *coaching.Plan `json:"coaching_plan"`
}

// Config configures the background HTTP uploader.
type Config struct {
	BaseURL     string
	APIToken    string
	Timeout     time.Duration
	QueueLength int
	Logger      *slog.Logger
}

type uploadJob struct {
	lap   telemetry.CompletedLap
	batch *buffer.SampleBatch
}

// Uploader dispatches completed laps asynchronously in a dedicated background
// worker so network latency never blocks or allocates inside the 20 Hz loop.
type Uploader struct {
	baseURL   string
	endpoint  string
	apiToken  string
	client    *http.Client
	pool      *buffer.SnapshotPool
	evaluator *coaching.Evaluator
	speaker   coaching.Speaker
	logger    *slog.Logger
	bufPool   sync.Pool
	jobs      chan uploadJob
	wg        sync.WaitGroup
}

// NewUploader constructs a non-blocking HTTP telemetry uploader.
func NewUploader(cfg Config, pool *buffer.SnapshotPool, evaluator *coaching.Evaluator, speaker coaching.Speaker) *Uploader {
	if cfg.Timeout <= 0 {
		cfg.Timeout = 8 * time.Second
	}
	if cfg.QueueLength <= 0 {
		cfg.QueueLength = 6
	}
	base := strings.TrimRight(strings.TrimSpace(cfg.BaseURL), "/")
	var endpoint string
	if base != "" {
		endpoint = base + "/api/v1/telemetry/lap"
	}

	u := &Uploader{
		baseURL:   base,
		endpoint:  endpoint,
		apiToken:  strings.TrimSpace(cfg.APIToken),
		client:    &http.Client{Timeout: cfg.Timeout},
		pool:      pool,
		evaluator: evaluator,
		speaker:   speaker,
		logger:    cfg.Logger,
		jobs:      make(chan uploadJob, cfg.QueueLength),
	}
	u.bufPool.New = func() any {
		return bytes.NewBuffer(make([]byte, 0, 256*1024))
	}
	return u
}

// Start launches the background upload worker tied to ctx.
func (u *Uploader) Start(ctx context.Context) {
	u.wg.Add(1)
	go func() {
		defer u.wg.Done()
		for {
			select {
			case <-ctx.Done():
				u.drainRemaining()
				return
			case job, ok := <-u.jobs:
				if !ok {
					return
				}
				u.processJob(ctx, job)
			}
		}
	}()
}

// Close waits for queued lap uploads to finish cleanly and notifies session end.
func (u *Uploader) Close() {
	close(u.jobs)
	u.wg.Wait()
	u.notifySessionEnd()
}

func (u *Uploader) notifySessionEnd() {
	if u.baseURL == "" {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, u.baseURL+"/api/v1/telemetry/session/end", bytes.NewReader([]byte(`{}`)))
	if err != nil {
		return
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	if u.apiToken != "" {
		req.Header.Set("Authorization", "Bearer "+u.apiToken)
	}
	resp, err := u.client.Do(req)
	if err == nil && resp != nil {
		_ = resp.Body.Close()
	}
}

// Enqueue schedules a completed lap for background HTTP POST without blocking.
// If the queue is full (e.g. backend offline for many laps), the batch is recycled immediately.
func (u *Uploader) Enqueue(lap telemetry.CompletedLap, batch *buffer.SampleBatch) bool {
	if batch != nil {
		lap.Samples = batch.Samples
	}
	select {
	case u.jobs <- uploadJob{lap: lap, batch: batch}:
		return true
	default:
		if u.pool != nil && batch != nil {
			u.pool.Release(batch)
		}
		if u.logger != nil {
			u.logger.Warn("upload queue full, dropping lap telemetry", slog.Int("lap", int(lap.LapNumber)))
		}
		return false
	}
}

func (u *Uploader) drainRemaining() {
	for {
		select {
		case job, ok := <-u.jobs:
			if !ok {
				return
			}
			// Attempt a short flush using a bounded background context before releasing pool memory.
			flushCtx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
			u.processJob(flushCtx, job)
			cancel()
		default:
			return
		}
	}
}

func (u *Uploader) processJob(ctx context.Context, job uploadJob) {
	if u.pool != nil && job.batch != nil {
		defer u.pool.Release(job.batch)
	}

	if u.endpoint == "" {
		if u.logger != nil {
			u.logger.Info("lap completed (dry-run, no API URL configured)",
				slog.Int("lap", int(job.lap.LapNumber)),
				slog.Uint64("lap_time_ms", uint64(job.lap.LapTimeMs)),
				slog.Bool("is_clean", job.lap.IsClean),
				slog.Int("samples", len(job.lap.Samples)),
			)
		}
		return
	}

	buf := u.bufPool.Get().(*bytes.Buffer)
	buf.Reset()
	defer u.bufPool.Put(buf)

	enc := json.NewEncoder(buf)
	if err := enc.Encode(&job.lap); err != nil {
		if u.logger != nil {
			u.logger.Error("failed to serialize lap JSON", slog.Any("err", err))
		}
		return
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, u.endpoint, bytes.NewReader(buf.Bytes()))
	if err != nil {
		if u.logger != nil {
			u.logger.Error("failed to build HTTP request", slog.Any("err", err))
		}
		return
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	if u.apiToken != "" {
		req.Header.Set("Authorization", "Bearer "+u.apiToken)
	}

	resp, err := u.client.Do(req)
	if err != nil {
		if u.logger != nil {
			u.logger.Warn("backend unreachable on lap upload",
				slog.Int("lap", int(job.lap.LapNumber)),
				slog.Any("err", err),
			)
		}
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
		if u.logger != nil {
			u.logger.Warn("backend rejected lap upload",
				slog.Int("status", resp.StatusCode),
				slog.Int("lap", int(job.lap.LapNumber)),
			)
		}
		return
	}

	var parsed LapResponse
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&parsed); err != nil {
		if u.logger != nil {
			u.logger.Warn("invalid coaching plan JSON from backend", slog.Any("err", fmt.Errorf("decode: %w", err)))
		}
		return
	}

	if parsed.CoachingPlan != nil {
		// Pre-warm AI voice clips into RAM before activating the new coaching plan
		// so spatial trigger playback has zero I/O latency on track.
		if u.speaker != nil {
			if err := u.speaker.Preload(ctx, parsed.CoachingPlan); err != nil && u.logger != nil {
				u.logger.Warn("audio preload warning", slog.Any("err", err))
			}
		}
		if u.evaluator != nil {
			u.evaluator.SetPlan(parsed.CoachingPlan)
		}
		if u.logger != nil {
			u.logger.Info("coaching plan updated",
				slog.String("plan_id", parsed.CoachingPlan.PlanID),
				slog.String("focus_turn", parsed.CoachingPlan.FocusTurnName),
				slog.Int("triggers", len(parsed.CoachingPlan.Triggers)),
			)
		}
	}
}
