# Contrato de API: Telemetría y Plan de Coaching (`v1`)

Este documento define el contrato HTTP/JSON entre `pacefriend-client` (Go) y el backend en Laravel (`POST /api/v1/telemetry/lap`), preparado desde el inicio para soportar **síntesis de voz natural con IA** cacheada en el servidor y reproducida desde RAM en el cliente (0 impacto en CPU/GPU mientras se conduce).

---

## `POST /api/v1/telemetry/lap`

### Headers

- `Content-Type: application/json`
- `Accept: application/json`
- `Authorization: Bearer <API_TOKEN>` (opcional en entorno local de desarrollo)

### Request Body

```json
{
  "user_id": "driver-01",
  "track_slug": "spa-francorchamps-gp",
  "track_name": "Circuit de Spa-Francorchamps",
  "track_length_km": 7.004,
  "car_name": "Porsche 911 GT3 R (992)",
  "session_id": 104521,
  "sub_session_id": 68912044,
  "lap_number": 4,
  "lap_time_ms": 138450,
  "is_clean": true,
  "incident_delta": 0,
  "sample_rate_hz": 20,
  "samples": [
    {
      "t_ms": 0,
      "pct": 0.0012,
      "speed": 64.82,
      "throttle": 1.0,
      "brake": 0.0,
      "lap": 4,
      "gear": 5,
      "flags": 0
    }
  ]
}
```

#### Unidades y convenciones de `samples`
- `t_ms`: Milisegundos transcurridos desde el inicio de la vuelta (`uint32`).
- `pct`: `LapDistPct` normalizado en `[0.0, 1.0)` (`float32`).
- `speed`: Velocidad en `m/s` tal como la entrega el SDK de iRacing (`float32`).
- `throttle`: Posición del acelerador en `[0.0, 1.0]` (`float32`).
- `brake`: Presión de pedal de freno en `[0.0, 1.0]` (`float32`).
- `lap`: Número de vuelta (`int32`).
- `gear`: Marcha engranada (`-1` reversa, `0` punto muerto, `1..N` avance).
- `flags`: Bitmask de estado (`1` = OffTrack, `2` = OnPitRoad, `4` = IncidentIncremented).

---

### Response Body (`200 OK` / `201 Created`)

Cuando aún no hay suficientes vueltas limpias (< 5) o no hay cambios respecto al plan activo, `coaching_plan` puede ser `null` o mantener el `plan_id` anterior.

```json
{
  "status": "ok",
  "lap_stored": true,
  "clean_laps_window": 5,
  "coaching_plan": {
    "plan_id": "plan_spa_t1_992_v3",
    "generated_at": "2026-11-27T08:15:00Z",
    "focus_turn": 1,
    "focus_turn_name": "La Source",
    "avg_time_loss_ms": 340,
    "triggers": [
      {
        "id": "t1_pre_brake",
        "turn": 1,
        "phase": "pre_brake",
        "trigger_pct": 0.965,
        "text": "En La Source aguanta la referencia y retrasa diez metros la frenada.",
        "audio_url": "http://localhost:8000/api/v1/audio/8f3a19c2e4b0.mp3",
        "audio_sha256": "8f3a19c2e4b0..."
      },
      {
        "id": "t1_post_exit",
        "turn": 1,
        "phase": "post_exit",
        "trigger_pct": 0.032,
        "text": "Suelta freno antes hacia el vértice para abrir gas limpio en bajada.",
        "audio_url": "http://localhost:8000/api/v1/audio/2c91b74d11a8.mp3",
        "audio_sha256": "2c91b74d11a8..."
      }
    ]
  }
}
```

### Estrategia de Audio IA (Fase 4)
1. El backend Laravel traduce el diagnóstico a frases naturales de ingeniero de pista y calcula `sha256(voice_id + ":" + text)`.
2. Si el clip ya existe en disco/S3 (`audio-cache`), reutiliza instantáneamente su URL sin coste ni latencia de generación; si no, lo sintetiza con el proveedor de voz neuronal (ej. ElevenLabs / OpenAI TTS) y lo cachea.
3. Al recibir un nuevo `coaching_plan`, una goroutine en segundo plano en el cliente Go descarga los clips (`audio_url`) una sola vez y los guarda decodificados en memoria RAM.
4. Cuando `LapDistPct` cruza `trigger_pct` en el bucle de 20 Hz, el cliente dispara el búfer de audio desde RAM sin I/O de disco ni red.
