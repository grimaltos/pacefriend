.PHONY: test bench build-linux build-windows build-windows-console run-sim clean

BINARY_NAME=pacefriend-client

test:
	go test -v -race ./...

bench:
	go test -bench=. -benchmem ./internal/...

build-linux:
	CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o bin/$(BINARY_NAME) ./cmd/pacefriend-client

# Windowless System Tray .exe (no black console window on Windows)
build-windows:
	GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags="-s -w -H=windowsgui" -o bin/$(BINARY_NAME).exe ./cmd/pacefriend-client

# Optional console variant for terminal debugging on Windows
build-windows-console:
	GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o bin/$(BINARY_NAME)-console.exe ./cmd/pacefriend-client

run-sim:
	go run ./cmd/pacefriend-client -sim -sim-laps 3 -sim-speedup 8

clean:
	rm -rf bin/
