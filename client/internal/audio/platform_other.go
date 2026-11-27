//go:build !windows

package audio

import (
	"os/exec"
	"sync"
)

var (
	detectOnce   sync.Once
	linuxCmdPath string
	linuxCmdArgs []string
)

func detectLinuxPlayer() {
	candidates := []struct {
		bin  string
		args []string
	}{
		{bin: "pw-play", args: nil},
		{bin: "paplay", args: nil},
		{bin: "aplay", args: []string{"-q"}},
		{bin: "ffplay", args: []string{"-nodisp", "-autoexit", "-loglevel", "quiet"}},
	}
	for _, c := range candidates {
		if path, err := exec.LookPath(c.bin); err == nil {
			linuxCmdPath = path
			linuxCmdArgs = c.args
			return
		}
	}
}

func requiresStagedFile(_ clipFormat) bool {
	return true
}

func playPlatformClip(clip cachedClip) error {
	detectOnce.Do(detectLinuxPlayer)
	if linuxCmdPath == "" || clip.stagedPath == "" {
		return nil
	}
	args := append(append([]string{}, linuxCmdArgs...), clip.stagedPath)
	return exec.Command(linuxCmdPath, args...).Run()
}

func speakPlatformFallback(_ string) error {
	return nil
}
