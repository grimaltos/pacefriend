package config

import (
	"encoding/json"
	"os"
	"path/filepath"
)

// FileConfig represents an optional pacefriend.json placed alongside pacefriend-client.exe
// so the windowless Windows Tray App works on double-click without command-line flags.
type FileConfig struct {
	APIURL   string `json:"api_url"`
	APIToken string `json:"api_token"`
	UserID   string `json:"user_id"`
	ShmName  string `json:"shm_name"`
	Mute     bool   `json:"mute"`
}

// LoadAdjacent reads pacefriend.json from the executable directory (or working directory).
func LoadAdjacent() FileConfig {
	var cfg FileConfig

	candidates := []string{"pacefriend.json"}
	if exePath, err := os.Executable(); err == nil {
		candidates = append([]string{filepath.Join(filepath.Dir(exePath), "pacefriend.json")}, candidates...)
	}

	for _, p := range candidates {
		raw, err := os.ReadFile(p)
		if err == nil && json.Unmarshal(raw, &cfg) == nil {
			return cfg
		}
	}
	return cfg
}
