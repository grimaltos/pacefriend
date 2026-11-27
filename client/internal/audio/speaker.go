package audio

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/pacefriend/client/internal/coaching"
)

const (
	MaxCachedClips = 32
	maxAudioBytes  = 8 << 20 // 8 MB safety cap per voice clip
)

type clipFormat uint8

const (
	formatWAV clipFormat = iota + 1
	formatMP3
)

type cachedClip struct {
	key        string
	format     clipFormat
	data       []byte
	stagedPath string
}

// Config configures the RAM-preloaded local voice playback engine.
type Config struct {
	Muted      bool
	APIToken   string
	HTTPClient *http.Client
	Logger     *slog.Logger
}

// Engine implements coaching.Speaker with background HTTP preloading into RAM,
// SHA-256 integrity verification, and a non-blocking playback queue (0 allocs in 20 Hz loop).
type Engine struct {
	muted      atomic.Bool
	apiToken   string
	httpClient *http.Client
	logger     *slog.Logger
	stageDir   string

	mu    sync.RWMutex
	clips map[string]cachedClip
	order []string

	playCh chan coaching.Trigger
	wg     sync.WaitGroup
}

// NewEngine creates and starts the background audio worker goroutine.
func NewEngine(cfg Config) *Engine {
	httpClient := cfg.HTTPClient
	if httpClient == nil {
		httpClient = &http.Client{Timeout: 10 * time.Second}
	}
	stageDir := filepath.Join(os.TempDir(), "pacefriend-audio")
	_ = os.MkdirAll(stageDir, 0o700)

	e := &Engine{
		apiToken:   strings.TrimSpace(cfg.APIToken),
		httpClient: httpClient,
		logger:     cfg.Logger,
		stageDir:   stageDir,
		clips:      make(map[string]cachedClip, MaxCachedClips),
		order:      make([]string, 0, MaxCachedClips),
		playCh:     make(chan coaching.Trigger, 2),
	}
	e.muted.Store(cfg.Muted)

	e.wg.Add(1)
	go e.playbackLoop()
	return e
}

// Close drains/stops the background playback worker and cleans staged temp files.
func (e *Engine) Close() {
	close(e.playCh)
	e.wg.Wait()
	if e.stageDir != "" {
		_ = os.RemoveAll(e.stageDir)
	}
}

// SetMuted atomically mutes or unmutes radio audio playback (e.g. from the System Tray).
func (e *Engine) SetMuted(muted bool) {
	e.muted.Store(muted)
}

// IsMuted reports whether radio playback is currently silenced.
func (e *Engine) IsMuted() bool {
	return e.muted.Load()
}

// CachedCount returns the number of voice clips currently resident in RAM.
func (e *Engine) CachedCount() int {
	e.mu.RLock()
	defer e.mu.RUnlock()
	return len(e.clips)
}

// Preload fetches any missing AI voice clips in the coaching plan into RAM before
// the spatial triggers become active on track.
func (e *Engine) Preload(ctx context.Context, plan *coaching.Plan) error {
	if plan == nil {
		return nil
	}

	var firstErr error
	for _, trg := range plan.Triggers {
		if trg.AudioURL == "" {
			continue
		}
		if err := e.preloadSingle(ctx, trg); err != nil && firstErr == nil {
			firstErr = err
		}
	}
	return firstErr
}

// Speak enqueues a spatial trigger for immediate playback without blocking
// or allocating on the heap inside the 20 Hz telemetry sampling loop.
func (e *Engine) Speak(trg coaching.Trigger) {
	select {
	case e.playCh <- trg:
	default:
		// Another radio message is already playing and one is queued; skip overlap cleanly.
	}
}

func (e *Engine) preloadSingle(ctx context.Context, trg coaching.Trigger) error {
	key := clipKey(trg)

	e.mu.RLock()
	_, exists := e.clips[key]
	e.mu.RUnlock()
	if exists {
		return nil
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, trg.AudioURL, nil)
	if err != nil {
		return err
	}
	if e.apiToken != "" {
		req.Header.Set("Authorization", "Bearer "+e.apiToken)
	}

	resp, err := e.httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("audio HTTP %d for %s", resp.StatusCode, trg.ID)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, maxAudioBytes))
	if err != nil {
		return err
	}
	if len(raw) < 16 {
		return errors.New("audio payload too small")
	}

	if len(trg.AudioSHA256) == 64 {
		sum := sha256.Sum256(raw)
		gotHex := hex.EncodeToString(sum[:])
		if !strings.EqualFold(gotHex, trg.AudioSHA256) {
			return fmt.Errorf("audio sha256 mismatch: want %s got %s", trg.AudioSHA256, gotHex)
		}
	}

	format := detectFormat(raw)
	stagedPath, err := e.stageClipIfNeeded(key, format, raw)
	if err != nil && e.logger != nil {
		e.logger.Warn("failed to stage audio clip", slog.Any("err", err))
	}

	clip := cachedClip{
		key:        key,
		format:     format,
		data:       raw,
		stagedPath: stagedPath,
	}

	e.mu.Lock()
	if _, already := e.clips[key]; !already {
		if len(e.order) >= MaxCachedClips {
			oldest := e.order[0]
			e.order = e.order[1:]
			if evicted, ok := e.clips[oldest]; ok && evicted.stagedPath != "" {
				_ = os.Remove(evicted.stagedPath)
			}
			delete(e.clips, oldest)
		}
		e.order = append(e.order, key)
	}
	e.clips[key] = clip
	e.mu.Unlock()

	return nil
}

func (e *Engine) playbackLoop() {
	defer e.wg.Done()
	for trg := range e.playCh {
		key := clipKey(trg)
		e.mu.RLock()
		clip, hasClip := e.clips[key]
		e.mu.RUnlock()

		if e.logger != nil {
			e.logger.Info("RADIO ENGINEER",
				slog.Int("turn", trg.Turn),
				slog.String("phase", string(trg.Phase)),
				slog.Float64("pct", float64(trg.TriggerPct)),
				slog.Bool("ram_audio", hasClip),
				slog.String("message", trg.Text),
			)
		}

		if e.muted.Load() {
			continue
		}

		if hasClip {
			if err := playPlatformClip(clip); err != nil && e.logger != nil {
				e.logger.Debug("audio playback warning", slog.Any("err", err))
			}
		} else {
			_ = speakPlatformFallback(trg.Text)
		}
	}
}

func (e *Engine) stageClipIfNeeded(key string, format clipFormat, data []byte) (string, error) {
	if !requiresStagedFile(format) || e.stageDir == "" {
		return "", nil
	}
	ext := ".mp3"
	if format == formatWAV {
		ext = ".wav"
	}
	path := filepath.Join(e.stageDir, key+ext)
	if err := os.WriteFile(path, data, 0o600); err != nil {
		return "", err
	}
	return path, nil
}

func clipKey(trg coaching.Trigger) string {
	if len(trg.AudioSHA256) == 64 {
		return strings.ToLower(trg.AudioSHA256)
	}
	sum := sha256.Sum256([]byte(trg.AudioURL))
	return hex.EncodeToString(sum[:])
}

func detectFormat(data []byte) clipFormat {
	if len(data) >= 12 && bytes.Equal(data[0:4], []byte("RIFF")) && bytes.Equal(data[8:12], []byte("WAVE")) {
		return formatWAV
	}
	return formatMP3
}
