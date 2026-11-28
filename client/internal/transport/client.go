package transport

import (
	"bytes"
	"context"
	"encoding/base64"
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
	"github.com/pacefriend/client/internal/irsdk"
	"github.com/pacefriend/client/internal/telemetry"
)

// LapResponse mirrors the JSON response returned by POST /api/v1/telemetry/lap.
type LapResponse struct {
	Status          string             `json:"status"`
	LapStored       bool               `json:"lap_stored"`
	CleanLapsWindow int                `json:"clean_laps_window"`
	CoachingPlan    *coaching.Plan     `json:"coaching_plan"`
	RadioCallouts   []coaching.Callout `json:"radio_callouts,omitempty"`
	PitCommands     []irsdk.PitCommand `json:"pit_commands,omitempty"`
}

// BriefingRequest is sent once upon entering track or when session/weather conditions shift.
type BriefingRequest struct {
	TrackSlug     string                 `json:"track_slug"`
	TrackName     string                 `json:"track_name,omitempty"`
	TrackLengthKm float64                `json:"track_length_km,omitempty"`
	CarName       string                 `json:"car_name"`
	SessionID     int                    `json:"session_id,omitempty"`
	SubSessionID  int                    `json:"sub_session_id,omitempty"`
	TrackTempC    float32                `json:"track_temp_c"`
	AirTempC      float32                `json:"air_temp_c"`
	IsWet         bool                   `json:"is_wet"`
	FuelLevelL    float32                `json:"fuel_level_l,omitempty"`
	SectorSplits  []float32              `json:"sector_splits,omitempty"`
	Reason        string                 `json:"reason"`
	Race          *telemetry.RaceContext `json:"race_context,omitempty"`
}

// HazardAlertRequest notifies the engineer of an immediate spun/off-track car ahead.
type HazardAlertRequest struct {
	TrackSlug  string  `json:"track_slug"`
	CarName    string  `json:"car_name"`
	AheadMeters int    `json:"ahead_meters"`
	LapDistPct float32 `json:"lap_dist_pct"`
}

// VoiceQueryRequest sends Push-To-Talk recorded driver speech + live race state to the engineer.
type VoiceQueryRequest struct {
	TrackSlug      string                 `json:"track_slug,omitempty"`
	TrackName      string                 `json:"track_name,omitempty"`
	TrackLengthKm  float64                `json:"track_length_km,omitempty"`
	CarName        string                 `json:"car_name,omitempty"`
	SessionID      int                    `json:"session_id,omitempty"`
	SubSessionID   int                    `json:"sub_session_id,omitempty"`
	AudioWAVBase64 string                 `json:"audio_wav_base64"`
	Race           *telemetry.RaceContext `json:"race_context,omitempty"`
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
	Transcript    string             `json:"transcript,omitempty"`
	ReplyText     string             `json:"reply_text,omitempty"`
	RadioCallouts []coaching.Callout `json:"radio_callouts,omitempty"`
	PitCommands   []irsdk.PitCommand `json:"pit_commands,omitempty"`
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
	isSector bool
	lap      telemetry.CompletedLap
	sector   telemetry.CompletedSector
	batch    *buffer.SampleBatch
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

// EnqueueHazardAlert dispatches an immediate ahead-hazard warning request.
func (u *Uploader) EnqueueHazardAlert(req HazardAlertRequest) {
	if u.baseURL == "" || req.TrackSlug == "" {
		return
	}
	select {
	case u.events <- asyncEvent{endpoint: "/api/v1/telemetry/hazard", payload: req}:
	default:
	}
}

// SendVoiceQuery uploads Push-To-Talk WAV audio + live race context and immediately plays
// the engineer's spoken reply and executes any returned iRacing Black Box pit commands.
func (u *Uploader) SendVoiceQuery(ctx context.Context, wavBytes []byte, meta telemetry.SessionMetadata, rc telemetry.RaceContext) {
	if u.baseURL == "" || len(wavBytes) == 0 {
		return
	}
	payload := VoiceQueryRequest{
		TrackSlug:      meta.TrackSlug,
		TrackName:      meta.TrackName,
		TrackLengthKm:  meta.TrackLengthKm,
		CarName:        meta.CarName,
		SessionID:      meta.SessionID,
		SubSessionID:   meta.SubSessionID,
		AudioWAVBase64: base64.StdEncoding.EncodeToString(wavBytes),
		Race:           &rc,
	}
	u.processAsyncEvent(ctx, asyncEvent{
		endpoint: "/api/v1/telemetry/voice-query",
		payload:  payload,
	})
}

// SendHeartbeat notifies the PaceFriend web panel that the local Windows client is online.
// Note: Radio volume is controlled exclusively by the local .exe and never overwritten by heartbeat.
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
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 8192))
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

// EnqueueSector schedules a completed intermediate partial/sector for background HTTP POST
// without blocking or allocating on the 20 Hz telemetry thread.
func (u *Uploader) EnqueueSector(sector telemetry.CompletedSector, batch *buffer.SampleBatch) bool {
	if batch != nil {
		sector.Samples = batch.Samples
	}
	select {
	case u.jobs <- uploadJob{isSector: true, sector: sector, batch: batch}:
		return true
	default:
		if u.pool != nil && batch != nil {
			u.pool.Release(batch)
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

	u.executePitCommands(parsed.PitCommands)
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

	targetURL := u.endpoint
	if job.isSector && u.baseURL != "" {
		targetURL = u.baseURL + "/api/v1/telemetry/sector"
	}

	if targetURL == "" {
		if u.logger != nil && !job.isSector {
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
	var encodeErr error
	if job.isSector {
		encodeErr = enc.Encode(&job.sector)
	} else {
		encodeErr = enc.Encode(&job.lap)
	}
	if encodeErr != nil {
		if u.logger != nil {
			u.logger.Error("failed to serialize telemetry JSON", slog.Any("err", encodeErr))
		}
		return
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, targetURL, bytes.NewReader(buf.Bytes()))
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

	u.executePitCommands(parsed.PitCommands)
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

func (u *Uploader) executePitCommands(cmds []irsdk.PitCommand) {
	if len(cmds) == 0 {
		return
	}
	go func() {
		if err := irsdk.ExecutePitCommands(cmds); err != nil && u.logger != nil {
			u.logger.Warn("pit command broadcast failed", slog.Any("err", err))
		} else if u.logger != nil {
			u.logger.Info("executed iRacing pit commands", slog.Int("count", len(cmds)))
		}
	}()
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
