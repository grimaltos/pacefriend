//go:build !windows

package irsdk

func dispatchPlatformPitSteps(_ []rawPitStep) error {
	return nil
}
