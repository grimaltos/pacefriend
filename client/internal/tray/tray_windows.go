//go:build windows

package tray

import (
	"runtime"
	"sync"
	"sync/atomic"
	"syscall"
	"unsafe"
)

const (
	wmDestroy       = 0x0002
	wmClose         = 0x0010
	wmQuit          = 0x0012
	wmNull          = 0x0000
	wmCommand       = 0x0111
	wmRButtonUp     = 0x0205
	wmContextMenu   = 0x007B
	wmTrayCallback  = 0x0400 + 42 // WM_USER + 42

	nimAdd    = 0x00000000
	nimModify = 0x00000001
	nimDelete = 0x00000002

	nifMessage = 0x00000001
	nifIcon    = 0x00000002
	nifTip     = 0x00000004

	mfString    = 0x00000000
	mfChecked   = 0x00000008
	mfGrayed    = 0x00000001
	mfSeparator = 0x00000800

	tpmRightButton = 0x0002
	tpmBottomAlign = 0x0020
	tpmReturnCmd   = 0x0100
	tpmNonotify    = 0x0080

	idiApplication = 32512
	swShownormal   = 1

	cmdToggleMute = 1001
	cmdOpenPanel  = 1002
	cmdExitApp    = 1003
	cmdTestRadio  = 1004
)

var (
	user32                       = syscall.NewLazyDLL("user32.dll")
	shell32                      = syscall.NewLazyDLL("shell32.dll")
	kernel32                     = syscall.NewLazyDLL("kernel32.dll")
	procGetModuleHandleW         = kernel32.NewProc("GetModuleHandleW")
	procRegisterClassExW         = user32.NewProc("RegisterClassExW")
	procCreateWindowExW          = user32.NewProc("CreateWindowExW")
	procDefWindowProcW           = user32.NewProc("DefWindowProcW")
	procGetMessageW              = user32.NewProc("GetMessageW")
	procTranslateMessage         = user32.NewProc("TranslateMessage")
	procDispatchMessageW         = user32.NewProc("DispatchMessageW")
	procPostMessageW             = user32.NewProc("PostMessageW")
	procDestroyWindow            = user32.NewProc("DestroyWindow")
	procPostQuitMessage          = user32.NewProc("PostQuitMessage")
	procCreatePopupMenu          = user32.NewProc("CreatePopupMenu")
	procAppendMenuW              = user32.NewProc("AppendMenuW")
	procDestroyMenu              = user32.NewProc("DestroyMenu")
	procGetCursorPos             = user32.NewProc("GetCursorPos")
	procSetForegroundWindow      = user32.NewProc("SetForegroundWindow")
	procTrackPopupMenu           = user32.NewProc("TrackPopupMenu")
	procCreateIconFromResourceEx = user32.NewProc("CreateIconFromResourceEx")
	procDestroyIcon              = user32.NewProc("DestroyIcon")
	procLoadIconW                = user32.NewProc("LoadIconW")
	procShellNotifyIconW         = shell32.NewProc("Shell_NotifyIconW")
	procShellExecuteW            = shell32.NewProc("ShellExecuteW")

	activeInstance atomic.Pointer[Manager]
)

type wndClassExW struct {
	cbSize        uint32
	style         uint32
	lpfnWndProc   uintptr
	cbClsExtra    int32
	cbWndExtra    int32
	hInstance     uintptr
	hIcon         uintptr
	hCursor       uintptr
	hbrBackground uintptr
	lpszMenuName  *uint16
	lpszClassName *uint16
	hIconSm       uintptr
}

type point struct {
	x int32
	y int32
}

type msgStruct struct {
	hwnd    uintptr
	message uint32
	wParam  uintptr
	lParam  uintptr
	time    uint32
	pt      point
}

// notifyIconDataW matches Win32 NOTIFYICONDATAW basic V1 layout (compatible across Win 10/11).
type notifyIconDataW struct {
	cbSize           uint32
	hWnd             uintptr
	uID              uint32
	uFlags           uint32
	uCallbackMessage uint32
	hIcon            uintptr
	szTip            [128]uint16
}

// Manager manages the native Windows System Tray icon and right-click context menu.
type Manager struct {
	opts      Options
	statusTxt atomic.Value // string
	hwnd      atomic.Uintptr
	hIcon     uintptr
	nid       notifyIconDataW
	wg        sync.WaitGroup
	closeOnce sync.Once
}

// Start launches the Win32 System Tray message loop on a dedicated OS thread.
func Start(opts Options) *Manager {
	m := &Manager{opts: opts}
	initial := opts.InitialStatus
	if initial == "" {
		initial = "Esperando sesión de iRacing..."
	}
	m.statusTxt.Store(initial)
	activeInstance.Store(m)

	ready := make(chan struct{})
	m.wg.Add(1)
	go m.loop(ready)
	<-ready
	return m
}

// SetStatus updates both the context menu header and hover tooltip in the Windows Tray.
func (m *Manager) SetStatus(status string) {
	if m == nil {
		return
	}
	m.statusTxt.Store(status)
	hwnd := m.hwnd.Load()
	if hwnd == 0 {
		return
	}
	nid := m.nid
	nid.uFlags = nifTip
	copyTip(&nid.szTip, "PaceFriend · "+status)
	_, _, _ = procShellNotifyIconW.Call(nimModify, uintptr(unsafe.Pointer(&nid)))
}

// Close removes the tray icon and stops the Win32 message pump cleanly.
func (m *Manager) Close() {
	if m == nil {
		return
	}
	m.closeOnce.Do(func() {
		if hwnd := m.hwnd.Load(); hwnd != 0 {
			_, _, _ = procPostMessageW.Call(hwnd, wmClose, 0, 0)
		}
		m.wg.Wait()
		activeInstance.CompareAndSwap(m, nil)
	})
}

func (m *Manager) loop(ready chan<- struct{}) {
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	defer m.wg.Done()

	hInst, _, _ := procGetModuleHandleW.Call(0)
	className, _ := syscall.UTF16PtrFromString("PaceFriendTrayWndClass")

	wndProcPtr := syscall.NewCallback(wndProc)
	wc := wndClassExW{
		cbSize:        uint32(unsafe.Sizeof(wndClassExW{})),
		lpfnWndProc:   wndProcPtr,
		hInstance:     hInst,
		lpszClassName: className,
	}
	_, _, _ = procRegisterClassExW.Call(uintptr(unsafe.Pointer(&wc)))

	title, _ := syscall.UTF16PtrFromString("PaceFriend")
	hwnd, _, _ := procCreateWindowExW.Call(
		0,
		uintptr(unsafe.Pointer(className)),
		uintptr(unsafe.Pointer(title)),
		0,
		0, 0, 0, 0,
		0, 0, hInst, 0,
	)
	if hwnd == 0 {
		close(ready)
		return
	}
	m.hwnd.Store(hwnd)

	m.hIcon = loadEmbeddedOrFallbackIcon()

	m.nid = notifyIconDataW{
		cbSize:           uint32(unsafe.Sizeof(notifyIconDataW{})),
		hWnd:             hwnd,
		uID:              1,
		uFlags:           nifMessage | nifIcon | nifTip,
		uCallbackMessage: wmTrayCallback,
		hIcon:            m.hIcon,
	}
	status, _ := m.statusTxt.Load().(string)
	copyTip(&m.nid.szTip, "PaceFriend · "+status)

	_, _, _ = procShellNotifyIconW.Call(nimAdd, uintptr(unsafe.Pointer(&m.nid)))
	close(ready)

	var msg msgStruct
	for {
		ret, _, _ := procGetMessageW.Call(uintptr(unsafe.Pointer(&msg)), 0, 0, 0)
		if int32(ret) <= 0 {
			break
		}
		_, _, _ = procTranslateMessage.Call(uintptr(unsafe.Pointer(&msg)))
		_, _, _ = procDispatchMessageW.Call(uintptr(unsafe.Pointer(&msg)))
	}

	_, _, _ = procShellNotifyIconW.Call(nimDelete, uintptr(unsafe.Pointer(&m.nid)))
	if m.hIcon != 0 {
		_, _, _ = procDestroyIcon.Call(m.hIcon)
	}
}

func wndProc(hwnd uintptr, msg uint32, wParam, lParam uintptr) uintptr {
	m := activeInstance.Load()
	switch msg {
	case wmTrayCallback:
		event := uint32(lParam & 0xFFFF)
		if (event == wmRButtonUp || event == wmContextMenu) && m != nil {
			m.showContextMenu(hwnd)
			return 0
		}
	case wmClose:
		_, _, _ = procDestroyWindow.Call(hwnd)
		return 0
	case wmDestroy:
		_, _, _ = procPostQuitMessage.Call(0)
		return 0
	}
	ret, _, _ := procDefWindowProcW.Call(hwnd, uintptr(msg), wParam, lParam)
	return ret
}

func (m *Manager) showContextMenu(hwnd uintptr) {
	hMenu, _, _ := procCreatePopupMenu.Call()
	if hMenu == 0 {
		return
	}
	defer func() { _, _, _ = procDestroyMenu.Call(hMenu) }()

	status, _ := m.statusTxt.Load().(string)
	appendMenuItem(hMenu, mfString|mfGrayed, 0, "PaceFriend · "+status)
	_, _, _ = procAppendMenuW.Call(hMenu, mfSeparator, 0, 0)

	if m.opts.OnTestRadio != nil {
		appendMenuItem(hMenu, mfString, cmdTestRadio, "Probar Radio del Ingeniero")
	}

	muteFlags := uintptr(mfString)
	if m.opts.IsMuted != nil && m.opts.IsMuted() {
		muteFlags |= mfChecked
	}
	appendMenuItem(hMenu, muteFlags, cmdToggleMute, "Silenciar Radio")

	if m.opts.PanelURL != "" {
		appendMenuItem(hMenu, mfString, cmdOpenPanel, "Abrir Panel Web")
	}

	_, _, _ = procAppendMenuW.Call(hMenu, mfSeparator, 0, 0)
	appendMenuItem(hMenu, mfString, cmdExitApp, "Salir")

	var pt point
	_, _, _ = procGetCursorPos.Call(uintptr(unsafe.Pointer(&pt)))
	_, _, _ = procSetForegroundWindow.Call(hwnd)

	cmd, _, _ := procTrackPopupMenu.Call(
		hMenu,
		tpmRightButton|tpmBottomAlign|tpmReturnCmd|tpmNonotify,
		uintptr(pt.x),
		uintptr(pt.y),
		0,
		hwnd,
		0,
	)
	_, _, _ = procPostMessageW.Call(hwnd, wmNull, 0, 0)

	switch cmd {
	case cmdTestRadio:
		if m.opts.OnTestRadio != nil {
			m.opts.OnTestRadio()
		}
	case cmdToggleMute:
		if m.opts.OnToggleMute != nil {
			m.opts.OnToggleMute()
		}
	case cmdOpenPanel:
		openBrowser(m.opts.PanelURL)
	case cmdExitApp:
		if m.opts.OnExit != nil {
			m.opts.OnExit()
		}
	}
}

func appendMenuItem(hMenu, flags, id uintptr, label string) {
	u16, err := syscall.UTF16PtrFromString(label)
	if err != nil {
		return
	}
	_, _, _ = procAppendMenuW.Call(hMenu, flags, id, uintptr(unsafe.Pointer(u16)))
}

func loadEmbeddedOrFallbackIcon() uintptr {
	if res, err := ExtractFirstICOImage(DefaultIconICO); err == nil && len(res) > 0 {
		hIcon, _, _ := procCreateIconFromResourceEx.Call(
			uintptr(unsafe.Pointer(&res[0])),
			uintptr(len(res)),
			1,          // fIcon = TRUE
			0x00030000, // Win32 required version constant
			32,
			32,
			0,
		)
		if hIcon != 0 {
			return hIcon
		}
	}
	fallback, _, _ := procLoadIconW.Call(0, idiApplication)
	return fallback
}

func openBrowser(url string) {
	verb, _ := syscall.UTF16PtrFromString("open")
	target, err := syscall.UTF16PtrFromString(url)
	if err != nil {
		return
	}
	_, _, _ = procShellExecuteW.Call(
		0,
		uintptr(unsafe.Pointer(verb)),
		uintptr(unsafe.Pointer(target)),
		0,
		0,
		swShownormal,
	)
}

func copyTip(dst *[128]uint16, text string) {
	for i := range dst {
		dst[i] = 0
	}
	u16, err := syscall.UTF16FromString(text)
	if err != nil {
		return
	}
	if len(u16) > 127 {
		u16 = u16[:127]
	}
	copy(dst[:], u16)
}
