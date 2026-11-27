//go:build !windows

package irsdk

import (
	"context"

	"github.com/pacefriend/client/internal/telemetry"
)

// SharedMemorySource is a non-Windows stub allowing cross-platform builds and tests.
type SharedMemorySource struct {
	preferredName string
}

// NewSharedMemorySource returns a stub on non-Windows platforms.
func NewSharedMemorySource(shmName string) *SharedMemorySource {
	return &SharedMemorySource{preferredName: shmName}
}

func (s *SharedMemorySource) Connect(_ context.Context) error {
	return ErrUnsupportedOS
}

func (s *SharedMemorySource) ReadFrame(_ *telemetry.RawFrame) (bool, error) {
	return false, ErrUnsupportedOS
}

func (s *SharedMemorySource) SessionMetadata() telemetry.SessionMetadata {
	return telemetry.SessionMetadata{}
}

func (s *SharedMemorySource) Close() error {
	return nil
}
