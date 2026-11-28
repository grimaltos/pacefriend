package config

import (
	"encoding/json"
	"os"
	"path/filepath"
	"sync"
)

var saveMu sync.Mutex

// PTTConfig stores the user's local Push-To-Talk button assignment (steering wheel / button box / keyboard).
type PTTConfig struct {
	DeviceType string `json:"device_type,omitempty"` // "keyboard" or "joystick"
	JoystickID int    `json:"joystick_id,omitempty"` // 0..15 for winmm joystick port
	Button     int    `json:"button,omitempty"`      // 0..31 button index (joystick) or Win32 VK code (keyboard)
	Label      string `json:"label,omitempty"`       // Human-readable label shown in System Tray
}

// EffectivePTT returns the configured PTT binding or a sensible default (Tecla F9) so radio Q&A works out-of-the-box.
func (c FileConfig) EffectivePTT() PTTConfig {
	if c.PTT.DeviceType == "joystick" && c.PTT.Button >= 0 && c.PTT.Button < 32 {
		lbl := c.PTT.Label
		if lbl == "" {
			lbl = "Volante/Botonera"
		}
		return PTTConfig{
			DeviceType: "joystick",
			JoystickID: c.PTT.JoystickID,
			Button:     c.PTT.Button,
			Label:      lbl,
		}
	}
	if c.PTT.DeviceType == "keyboard" && c.PTT.Button > 0 {
		lbl := c.PTT.Label
		if lbl == "" {
			lbl = "Teclado"
		}
		return PTTConfig{
			DeviceType: "keyboard",
			Button:     c.PTT.Button,
			Label:      lbl,
		}
	}
	return PTTConfig{
		DeviceType: "keyboard",
		Button:     0x78, // VK_F9
		Label:      "Tecla F9",
	}
}

// EffectiveVolume returns the local .exe volume percentage (0..100), defaulting to 85% when not yet set in JSON.
func (c FileConfig) EffectiveVolume() int {
	if c.Volume == nil {
		return 85
	}
	v := *c.Volume
	if v < 0 {
		return 0
	}
	if v > 100 {
		return 100
	}
	return v
}

// FileConfig represents pacefriend.json placed alongside pacefriend-client.exe.
// Volume, Mute, and PTT bindings are owned and persisted locally by the .exe.
type FileConfig struct {
	APIURL   string    `json:"api_url"`
	APIToken string    `json:"api_token"`
	UserID   string    `json:"user_id,omitempty"`
	ShmName  string    `json:"shm_name,omitempty"`
	Mute     bool      `json:"mute"`
	Volume   *int      `json:"volume,omitempty"`
	PTT      PTTConfig `json:"ptt,omitempty"`
}

// AdjacentPath resolves the target path for reading/writing pacefriend.json.
func AdjacentPath() string {
	if raw, err := os.ReadFile("pacefriend.json"); err == nil && len(raw) > 0 {
		return "pacefriend.json"
	}
	if exePath, err := os.Executable(); err == nil {
		exeAdj := filepath.Join(filepath.Dir(exePath), "pacefriend.json")
		if _, err := os.Stat(exeAdj); err == nil {
			return exeAdj
		}
		return exeAdj
	}
	return "pacefriend.json"
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

// SaveAdjacent atomically writes updated local settings (Volume, Mute, PTT) back to pacefriend.json.
func SaveAdjacent(update func(*FileConfig)) error {
	saveMu.Lock()
	defer saveMu.Unlock()

	target := AdjacentPath()
	cfg := LoadAdjacent()
	update(&cfg)

	encoded, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}
	tmp := target + ".tmp"
	if err := os.WriteFile(tmp, encoded, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, target)
}
