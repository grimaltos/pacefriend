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

// AudioSettings carries live-synced audio preferences from the PaceFriend web panel.
type AudioSettings struct {
	Volume *int `json:"volume,omitempty"`
}

// LapResponse mirrors the JSON response returned by POST /api/v1/telemetry/lap.
type LapResponse struct {
	Status          string             `json:"status"`
	LapStored       bool               `json:"lap_stored"`
	CleanLapsWindow int                `json:"clean_laps_window"`
	CoachingPlan    *coaching.Plan     `json:"coaching_plan"`
	RadioCallouts   []coaching.Callout `json:"radio_callouts,omitempty"`
	AudioSettings   *AudioSettings     `json:"audio_settings,omitempty"`
}

// BriefingRequest is sent once upon entering track or when weather conditions shift.
type BriefingRequest struct {
	TrackSlug     string  `json:"track_slug"`
	TrackName     string  `json:"track_name,omitempty"`
	TrackLengthKm float64 `json:"track_length_km,omitempty"`
	CarName       string  `json:"car_name"`
	SessionID     int     `json:"session_id,omitempty"`
	SubSessionID  int     `json:"sub_session_id,omitempty"`
	TrackTempC    float32 `json:"track_temp_c"`
	AirTempC      float32 `json:"air_temp_c"`
	IsWet         bool    `json:"is_wet"`
	FuelLevelL    float32 `json:"fuel_level_l,omitempty"`
	Reason        string  `json:"reason"`
}

// PitStopRequest carries unlocked tyre pyrometer readings upon stopping in pits or garage.
type PitStopRequest struct {
	LFTempC       float32 `json:"lf_temp_c"`
	RFTempC       float32 `json:"rf_temp_c"`
	LRTempC       float32 `json:"lr_temp_c"`
	RRTempC       float32 `json:"rr_temp_c"`
	LapsCompleted int     `json:"laps_completed"`
}

type genericRadioResponse struct {
	Status        string             `json:"status"`
	RadioCallouts []coaching.Callout `json:"radio_callouts,omitempty"`
	AudioSettings *AudioSettings     `json:"audio_settings,omitempty"`
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

type asyncEvent struct {
	endpoint string
	payload  any
}

// Uploader dispatches completed laps and live engineer events asynchronously in
// dedicated background workers so network latency never blocks or allocates inside the 20 Hz loop.
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
	events    chan asyncEvent
	wg        sync.WaitGroup
}

// NewUploader constructs a non-blocking HTTP telemetry uploader.
func NewUploader(cfg Config, pool *buffer.SnapshotPool, evaluator *coaching.Evaluator, speaker coaching.Speaker) *Uploader {
	if cfg.Timeout <= 0 {
		cfg.Timeout = 14 * time.Second
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
		events:    make(chan asyncEvent, 8),
	}
	u.bufPool.New = func() any {
		return bytes.NewBuffer(make([]byte, 0, 256*1024))
	}
	return u
}

// Start launches the background upload and event workers tied to ctx.
func (u *Uploader) Start(ctx context.Context) {
	u.wg.Add(2)
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

	go func() {
		defer u.wg.Done()
		for {
			select {
			case <-ctx.Done():
				return
			case ev, ok := <-u.events:
				if !ok {
					return
				}
				u.processAsyncEvent(ctx, ev)
			}
		}
	}()
}

// Close waits for queued lap uploads to finish cleanly and notifies session end.
func (u *Uploader) Close() {
	close(u.jobs)
	close(u.events)
	u.wg.Wait()
	u.NotifySessionEnd()
}

// EnqueueBriefing requests a track/weather/fuel/target greeting asynchronously.
func (u *Uploader) EnqueueBriefing(req BriefingRequest) {
	if u.baseURL == "" || req.TrackSlug == "" || req.CarName == "" {
		return
	}
	select {
	case u.events <- asyncEvent{endpoint: "/api/v1/telemetry/briefing", payload: req}:
	default:
	}
}

// EnqueuePitStop sends unlocked pit-box/garage tyre temperatures asynchronously.
func (u *Uploader) EnqueuePitStop(req PitStopRequest) {
	if u.baseURL == "" {
		return
	}
	select {
	case u.events <- asyncEvent{endpoint: "/api/v1/telemetry/pit-stop", payload: req}:
	default:
	}
}

// TriggerRadioTest requests an immediate voice test clip (e.g. from the System Tray menu).
func (u *Uploader) TriggerRadioTest() {
	if u.baseURL == "" {
		return
	}
	select {
	case u.events <- asyncEvent{endpoint: "/api/v1/telemetry/radio-test", payload: map[string]string{}}:
	default:
	}
}

// SendHeartbeat notifies the PaceFriend web panel that the local Windows client is online
// and syncs live volume changes from the user's web settings.
func (u *Uploader) SendHeartbeat(ctx context.Context, state string, trackName string) error {
	if u.baseURL == "" {
		return fmt.Errorf("missing api_url (check pacefriend.json)")
	}
	hbCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	payload, _ := json.Marshal(map[string]string{
		"state":      state,
		"track_name": trackName,
	})

	req, err := http.NewRequestWithContext(hbCtx, http.MethodPost, u.baseURL+"/api/v1/telemetry/heartbeat", bytes.NewReader(payload))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	if u.apiToken != "" {
		req.Header.Set("Authorization", "Bearer "+u.apiToken)
	}

	resp, err := u.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("heartbeat HTTP %d", resp.StatusCode)
	}

	var parsed genericRadioResponse
	if err := json.NewDecoder(io.LimitReader(resp.Body, 64*1024)).Decode(&parsed); err == nil {
		u.applyAudioSettings(parsed.AudioSettings)
	}
	return nil
}

// NotifySessionEnd informs the backend that the active 3D simulator session has closed.
func (u *Uploader) NotifySessionEnd() {
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

func (u *Uploader) processAsyncEvent(ctx context.Context, ev asyncEvent) {
	if u.baseURL == "" {
		return
	}
	raw, err := json.Marshal(ev.payload)
	if err != nil {
		return
	}

	reqCtx, cancel := context.WithTimeout(ctx, 14*time.Second)
	defer cancel()

	req, err := http.NewRequestWithContext(reqCtx, http.MethodPost, u.baseURL+ev.endpoint, bytes.NewReader(raw))
	if err != nil {
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
			u.logger.Warn("engineer radio event request failed", slog.String("endpoint", ev.endpoint), slog.Any("err", err))
		}
		return
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		if u.logger != nil {
			u.logger.Warn("engineer radio event rejected", slog.String("endpoint", ev.endpoint), slog.Int("status", resp.StatusCode))
		}
		return
	}

	var parsed genericRadioResponse
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&parsed); err != nil {
		return
	}

	u.applyAudioSettings(parsed.AudioSettings)
	u.dispatchRadioCallouts(ctx, parsed.RadioCallouts)
}

func (u *Uploader) drainRemaining() {
	for {
		select {
		case job, ok := <-u.jobs:
			if !ok {
				return
			}
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

	u.applyAudioSettings(parsed.AudioSettings)
	u.dispatchRadioCallouts(ctx, parsed.RadioCallouts)

	if parsed.CoachingPlan != nil {
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

func (u *Uploader) applyAudioSettings(settings *AudioSettings) {
	if settings == nil || settings.Volume == nil {
		return
	}
	if imm, ok := u.speaker.(coaching.ImmediateSpeaker); ok {
		imm.SetVolume(*settings.Volume)
	}
}

func (u *Uploader) dispatchRadioCallouts(ctx context.Context, callouts []coaching.Callout) {
	if len(callouts) == 0 || u.speaker == nil {
		return
	}
	if imm, ok := u.speaker.(coaching.ImmediateSpeaker); ok {
		if err := imm.PlayCallouts(ctx, callouts); err != nil && u.logger != nil {
			u.logger.Warn("radio callout playback warning", slog.Any("err", err))
		}
	}
}
