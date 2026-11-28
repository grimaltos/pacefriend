//go:build !windows

package ptt

import (
	"context"

	"github.com/pacefriend/client/internal/config"
)

func runPlatformLoop(ctx context.Context, _ *Listener) {
	<-ctx.Done()
}

func captureNextPlatformInput(ctx context.Context) (config.PTTConfig, bool) {
	<-ctx.Done()
	return config.PTTConfig{}, false
}
