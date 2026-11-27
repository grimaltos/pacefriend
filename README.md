# PaceFriend

Herramienta avanzada de coaching en tiempo real para **iRacing** con mentalidad de ingeniero de pista: cero impacto en FPS en Windows, emparejamiento dinámico de vueltas de referencia según temperatura/humedad de pista, análisis sobre ventana deslizante de 5 vueltas limpias, órdenes personalizadas con el nombre del piloto y generación bajo demanda de **Informes PRO Post-Sesión**.

## Estructura del Monorepo

```text
pacefriend/
├── client/              # Componente A: Cliente ligero en Go (syscall Windows + simulador Linux)
├── backend/             # Componente B: Laravel + PostgreSQL + Inertia/Vue 3 + Motor IA
├── docker-compose.yml   # Servicio PostgreSQL 17 listo para levantar
└── docs/
    └── contracts/
        └── telemetry-api.md
```

## Estado de la Hoja de Ruta

- [x] **Fase 1: El Puente Local (`client/` en Go)**
  - Lectura nativa `Local\IRacingData` / `Local\IRacingMemMapFileName` (`OpenFileMappingW` + `MapViewOfFile`).
  - Indexado dinámico de telemetría + temperatura de asfalto/aire y estado húmedo (`TrackTempCrew`, `AirTemp`, `TrackWetness`).
  - Búfer circular sin punteros (**0 B/op**, **0 allocs/op** a 20 Hz) y subida asíncrona.
- [x] **Fase 2: Estructura de Datos, Autenticación y Panel Web (`backend/` Laravel + Vue 3)**
  - Registro, Login, configuración de `driver_callsign` y gestión de tokens **Laravel Sanctum** para vincular `pacefriend-client.exe`.
  - Esquema PostgreSQL con `jsonb` e índices nativos `GIN` (`tracks`, `reference_laps`, `driving_sessions`, `laps`, `session_reports`, `voice_clips`).
  - Carga de múltiples vueltas de referencia: parser binario nativo `.ibt` de iRacing, `.json` y promoción en 1 clic desde vueltas limpias propias.
- [x] **Fase 3: El Cerebro Analítico, Garage 61, Voz IA Cacheada e Informe PRO**
  - `ReferenceMatcherService` + `Garage61ImporterService` (Opción A manual por enlace/análisis/buscador + Opción C *Autopilot Progresivo* en pista sobre el catálogo curado `tracks_catalog.json`).
  - `CoachDiagnosticService`: ventana deslizante de 5 vueltas limpias (filtrando *outliers* de tráfico), detección de vicio crónico y traducción natural llamando al piloto por su nombre.
  - `VoiceSynthesisManager` (ElevenLabs + sintetizador WAV local sin coste con caché SHA-256) y `ProSessionReportService` (Gemini / OpenAI / Ingeniero Local).
- [x] **Fase 4: Reproducción de Audio en RAM en el Cliente Go**
  - `internal/audio`: descarga y verificación `SHA-256` en segundo plano al llegar el `coaching_plan`, reproducción directa desde RAM en Windows mediante `winmm.dll` (`PlaySoundW` con `SND_MEMORY` para WAV y `mciSendStringW` para MP3, **0 allocs/op** en el bucle de 20 Hz) y cierre limpio de sesión (`/api/v1/telemetry/session/end`).

## Arranque Rápido

### 1. Backend & Panel Web
```bash
# Opcional: levantar PostgreSQL 17 con Docker (en local también soporta SQLite para desarrollo rápido)
docker compose up -d

cd backend
php artisan migrate --seed
npm run build
php artisan serve
```

### 2. Cliente Go
```bash
cd client

# Probar en Linux enviando vueltas simuladas al backend local
go run ./cmd/pacefriend-client -sim -sim-speedup 20 -api-url "http://127.0.0.1:8000" -api-token "<TU_TOKEN_SANCTUM>"

# Compilar binario estático para Windows
GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o bin/pacefriend-client.exe ./cmd/pacefriend-client
```
