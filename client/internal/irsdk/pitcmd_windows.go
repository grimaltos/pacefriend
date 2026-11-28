//go:build windows

package irsdk

import (
	"fmt"
	"sync"
	"syscall"
	"time"
	"unsafe"
)

const (
	hwndBroadcast       uintptr = 0xFFFF
	irsdkBroadcastMsgID         = "IRSDK_BROADCASTMSGNAME"
)

var (
	user32Pit                 = syscall.NewLazyDLL("user32.dll")
	procRegisterWindowMessage = user32Pit.NewProc("RegisterWindowMessageW")
	procSendNotifyMessage     = user32Pit.NewProc("SendNotifyMessageW")

	broadcastOnce sync.Once
	broadcastMsg  uintptr
)

func resolveBroadcastMsgID() uintptr {
	broadcastOnce.Do(func() {
		namePtr, err := syscall.UTF16PtrFromString(irsdkBroadcastMsgID)
		if err != nil {
			return
		}
		id, _, _ := procRegisterWindowMessage.Call(uintptr(unsafe.Pointer(namePtr)))
		broadcastMsg = id
	})
	return broadcastMsg
}

func dispatchPlatformPitSteps(steps []rawPitStep) error {
	msgID := resolveBroadcastMsgID()
	if msgID == 0 {
		return fmt.Errorf("irsdk: RegisterWindowMessageW(%s) returned 0", irsdkBroadcastMsgID)
	}

	for i, st := range steps {
		if i > 0 {
			// Give iRacing's 60 Hz UI loop >2 ticks between queued pit commands so none coalesce.
			time.Sleep(45 * time.Millisecond)
		}
		// Official SDK packing: SendNotifyMessage(HWND_BROADCAST, msgId, MAKELONG(msg, var1), var2)
		wParam := uintptr(BroadcastPitCommand) | (uintptr(st.mode) << 16)
		lParam := uintptr(st.val)
		r1, _, err := procSendNotifyMessage.Call(hwndBroadcast, msgID, wParam, lParam)
		if r1 == 0 {
			return fmt.Errorf("irsdk: SendNotifyMessageW failed on pit mode %d: %w", st.mode, err)
		}
	}
	return nil
}
