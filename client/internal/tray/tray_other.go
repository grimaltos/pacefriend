//go:build !windows

package tray

// Manager is a non-Windows no-op stub so Linux simulator builds share the same API.
type Manager struct{}

func Start(_ Options) *Manager {
	return &Manager{}
}

func (m *Manager) SetStatus(_ string) {}

func (m *Manager) Close() {}
