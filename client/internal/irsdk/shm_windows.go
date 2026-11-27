//go:build windows

package irsdk

import (
	"context"
	"fmt"
	"syscall"
	"unsafe"

	"github.com/pacefriend/client/internal/telemetry"
)

const (
	fileMapRead = 0x0004
	memCommit   = 0x1000
)

var (
	kernel32            = syscall.NewLazyDLL("kernel32.dll")
	procOpenFileMapping = kernel32.NewProc("OpenFileMappingW")
	procMapViewOfFile   = kernel32.NewProc("MapViewOfFile")
	procUnmapViewOfFile = kernel32.NewProc("UnmapViewOfFile")
	procVirtualQuery    = kernel32.NewProc("VirtualQuery")
)

// memoryBasicInformation matches Win32 MEMORY_BASIC_INFORMATION on both amd64 (48B) and 386 (28B).
type memoryBasicInformation struct {
	BaseAddress       uintptr
	AllocationBase    uintptr
	AllocationProtect uint32
	RegionSize        uintptr
	State             uint32
	Protect           uint32
	Type              uint32
}

// SharedMemorySource connects directly to iRacing's Windows shared memory mapping
// via Win32 syscalls (OpenFileMappingW + MapViewOfFile) with zero CGO overhead.
type SharedMemorySource struct {
	preferredName     string
	activeName        string
	mappingHandle     syscall.Handle
	viewAddr          uintptr
	mem               []byte
	varIndex          *VarIndex
	lastSessionUpd    int32
	lastNumVars       int32
	lastVarHdrOff     int32
	disconnectedTicks int
	sessionMetadata   telemetry.SessionMetadata
}

// NewSharedMemorySource creates a native Windows shared memory telemetry reader.
// If shmName is empty or matches one of the standard SDK names, it tries
// Local\IRSDKMemMapFileName first and falls back automatically to legacy/spec names.
func NewSharedMemorySource(shmName string) *SharedMemorySource {
	return &SharedMemorySource{
		preferredName:  shmName,
		lastSessionUpd: -1,
	}
}

// Connect opens the Windows file mapping and maps its entire read-only view into process address space.
func (s *SharedMemorySource) Connect(_ context.Context) error {
	_ = s.Close()

	candidates := s.candidateNames()
	var lastErr error
	var handle syscall.Handle
	var chosen string

	for _, name := range candidates {
		h, err := openFileMappingReadOnly(name)
		if err == nil {
			handle = h
			chosen = name
			break
		}
		lastErr = err
	}
	if handle == 0 {
		return fmt.Errorf("open shared memory (%v): %w", candidates, lastErr)
	}

	addr, _, mapErr := procMapViewOfFile.Call(
		uintptr(handle),
		uintptr(fileMapRead),
		0,
		0,
		0, // 0 maps the full shared memory object
	)
	if addr == 0 {
		_ = syscall.CloseHandle(handle)
		return fmt.Errorf("MapViewOfFile(%s): %w", chosen, mapErr)
	}

	s.mappingHandle = handle
	s.viewAddr = addr
	s.activeName = chosen
	s.lastSessionUpd = -1
	s.disconnectedTicks = 0
	s.varIndex = nil

	// Decode the 112-byte SDK header directly before relying on VirtualQuery page boundaries.
	hdrBytes := unsafe.Slice((*byte)(unsafe.Pointer(addr)), HeaderSize)
	var hdr Header
	if err := ParseHeader(hdrBytes, &hdr); err != nil {
		_ = s.Close()
		return err
	}
	if hdr.Status&StatusConnected == 0 || hdr.NumVars <= 0 || hdr.BufLen <= 0 {
		_ = s.Close()
		return ErrSimDisconnected
	}

	mappedSize := RequiredMemorySize(&hdr)
	if regionSize, err := queryMappedRegionSize(addr); err == nil && regionSize > mappedSize {
		mappedSize = regionSize
	}

	s.mem = unsafe.Slice((*byte)(unsafe.Pointer(addr)), mappedSize)

	idx, err := IndexVariables(s.mem, &hdr)
	if err != nil {
		_ = s.Close()
		return err
	}
	s.varIndex = idx
	s.lastNumVars = hdr.NumVars
	s.lastVarHdrOff = hdr.VarHeaderOffset
	s.refreshSessionMetadataIfNeeded(&hdr)

	return nil
}

// ReadFrame reads the newest 60 Hz sample from mapped memory in O(1) with 0 allocations.
func (s *SharedMemorySource) ReadFrame(out *telemetry.RawFrame) (bool, error) {
	if len(s.mem) < HeaderSize {
		return false, ErrMemoryTooSmall
	}

	var hdr Header
	if err := ParseHeader(s.mem, &hdr); err != nil {
		return false, err
	}
	if hdr.Status&StatusConnected == 0 {
		s.disconnectedTicks++
		// After ~1s (20 ticks at 20 Hz) of disconnected status, release the handle so
		// Windows can clean up the closed session mapping and wait for the next session.
		if s.disconnectedTicks >= 20 {
			return false, ErrSimDisconnected
		}
		return false, nil
	}
	s.disconnectedTicks = 0

	if needed := RequiredMemorySize(&hdr); needed > len(s.mem) && s.viewAddr != 0 {
		s.mem = unsafe.Slice((*byte)(unsafe.Pointer(s.viewAddr)), needed)
	}

	// Re-index dynamically if iRacing initialized or updated the variable table.
	if s.varIndex == nil || hdr.NumVars != s.lastNumVars || hdr.VarHeaderOffset != s.lastVarHdrOff {
		idx, err := IndexVariables(s.mem, &hdr)
		if err != nil {
			return false, err
		}
		s.varIndex = idx
		s.lastNumVars = hdr.NumVars
		s.lastVarHdrOff = hdr.VarHeaderOffset
	}

	// Re-parse SessionInfo YAML only when iRacing increments sessionInfoUpdate.
	s.refreshSessionMetadataIfNeeded(&hdr)

	return ReadFrameFromMemory(s.mem, s.varIndex, out)
}

// SessionMetadata returns the latest parsed track & car metadata.
func (s *SharedMemorySource) SessionMetadata() telemetry.SessionMetadata {
	return s.sessionMetadata
}

// Close unmaps the memory view and closes the Win32 file mapping handle.
func (s *SharedMemorySource) Close() error {
	var firstErr error
	if s.viewAddr != 0 {
		r1, _, err := procUnmapViewOfFile.Call(s.viewAddr)
		if r1 == 0 && firstErr == nil {
			firstErr = err
		}
		s.viewAddr = 0
	}
	s.mem = nil
	s.varIndex = nil
	if s.mappingHandle != 0 {
		if err := syscall.CloseHandle(s.mappingHandle); err != nil && firstErr == nil {
			firstErr = err
		}
		s.mappingHandle = 0
	}
	return firstErr
}

func (s *SharedMemorySource) refreshSessionMetadataIfNeeded(hdr *Header) {
	if hdr.SessionInfoUpdate == s.lastSessionUpd {
		return
	}
	start := int(hdr.SessionInfoOffset)
	end := start + int(hdr.SessionInfoLen)
	if start >= HeaderSize && end > start && end <= len(s.mem) {
		s.sessionMetadata = ParseSessionInfoYAML(s.mem[start:end])
		s.lastSessionUpd = hdr.SessionInfoUpdate
	}
}

func (s *SharedMemorySource) candidateNames() []string {
	defaults := []string{OfficialShmName, LegacyShmName, SpecShmName}
	if s.preferredName == "" ||
		s.preferredName == OfficialShmName ||
		s.preferredName == LegacyShmName ||
		s.preferredName == SpecShmName {
		return defaults
	}
	return append([]string{s.preferredName}, defaults...)
}

func openFileMappingReadOnly(name string) (syscall.Handle, error) {
	utf16Name, err := syscall.UTF16PtrFromString(name)
	if err != nil {
		return 0, err
	}
	r1, _, callErr := procOpenFileMapping.Call(
		uintptr(fileMapRead),
		0,
		uintptr(unsafe.Pointer(utf16Name)),
	)
	if r1 == 0 {
		return 0, callErr
	}
	return syscall.Handle(r1), nil
}

func queryMappedRegionSize(addr uintptr) (int, error) {
	var total uintptr
	cur := addr

	for i := 0; i < 512; i++ {
		var mbi memoryBasicInformation
		r1, _, callErr := procVirtualQuery.Call(
			cur,
			uintptr(unsafe.Pointer(&mbi)),
			unsafe.Sizeof(mbi),
		)
		if r1 == 0 {
			if total > 0 {
				break
			}
			return 0, callErr
		}
		if i > 0 && mbi.AllocationBase != addr {
			break
		}
		if mbi.State != memCommit || mbi.RegionSize == 0 {
			break
		}
		total += mbi.RegionSize
		cur = mbi.BaseAddress + mbi.RegionSize
	}

	return int(total), nil
}
