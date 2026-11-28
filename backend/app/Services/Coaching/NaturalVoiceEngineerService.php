<?php

namespace App\Services\Coaching;

use App\Models\DrivingSession;
use App\Models\Lap;
use App\Models\Track;
use App\Models\User;
use App\Services\Voice\VoiceSynthesisManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class NaturalVoiceEngineerService
{
    private const ALLOWED_PIT_ACTIONS = [
        'clear_all',
        'clear_tires',
        'all_tires',
        'front_tires',
        'rear_tires',
        'left_tires',
        'right_tires',
        'lf',
        'rf',
        'lr',
        'rr',
        'fuel',
        'clear_fuel',
        'compound',
        'fast_repair',
        'clear_fr',
        'tearoff',
        'clear_ws',
    ];

    public function __construct(
        private readonly VoiceSynthesisManager $voiceManager,
        private readonly RaceStrategyService $raceStrategy,
    ) {}

    /**
     * Transcribe Push-To-Talk driver audio (or accept text transcript), resolve natural Spanish
     * race questions & pit-box commands, update pending confirmations, and synthesize radio reply.
     *
     * @param  array<string, mixed>  $raceCtx
     * @return array{
     *   transcript: string,
     *   reply_text: string,
     *   radio_callouts: array<int, array{id: string, text: string, audio_url: string, audio_sha256: string}>,
     *   pit_commands: array<int, array{action: string, value: int}>
     * }
     */
    public function handleVoiceQuery(
        User $user,
        ?DrivingSession $session,
        ?Track $track,
        ?string $audioWavBase64,
        ?string $directTranscript,
        array $raceCtx
    ): array {
        $radio = $user->radioSettings();
        $callsign = $user->spokenCallsign();
        $groqKey = $user->resolveGroqApiKey();

        $transcript = trim((string) $directTranscript);
        if ($transcript === '' && filled($audioWavBase64)) {
            $wavBytes = base64_decode((string) $audioWavBase64, true);
            if ($wavBytes !== false && strlen($wavBytes) > 44 && $groqKey !== null) {
                $transcript = $this->transcribeWithGroqWhisper($wavBytes, $groqKey);
            }
        }

        if ($transcript === '') {
            $msg = $groqKey === null
                ? "{$callsign}, configura tu clave gratuita de Groq en ajustes para transcribir el micrófono en vivo."
                : "No te he copiado bien {$callsign}, repite por favor.";

            return $this->formatOutput('', $msg, [], $radio);
        }

        // Compute live strategic telemetry numbers so both Llama 3.3 and deterministic engine use exact math
        $facts = $this->buildLiveStrategicFacts($session, $track, $raceCtx);
        $activePlan = is_array($session?->active_coaching_plan) ? $session->active_coaching_plan : [];
        $pendingPit = is_array($activePlan['_pending_pit'] ?? null) ? $activePlan['_pending_pit'] : null;

        // 1. Instant deterministic confirmation / cancellation of pending engineer pit proposal
        if ($pendingPit !== null) {
            $decision = $this->classifyConfirmationIntent($transcript);
            if ($decision === 'confirm') {
                unset($activePlan['_pending_pit']);
                $session?->update(['active_coaching_plan' => $activePlan !== [] ? $activePlan : null]);
                $cmds = $this->sanitizePitCommands($pendingPit['commands'] ?? []);
                $summary = (string) ($pendingPit['summary'] ?? 'estrategia');
                $reply = "Confirmado {$callsign}: {$summary} listo en tu box.";

                return $this->formatOutput($transcript, $reply, $cmds, $radio);
            }
            if ($decision === 'reject') {
                unset($activePlan['_pending_pit']);
                $session?->update(['active_coaching_plan' => $activePlan !== [] ? $activePlan : null]);
                $reply = "Recibido {$callsign}, mantenemos el box sin cambios.";

                return $this->formatOutput($transcript, $reply, [], $radio);
            }
        }

        // 2. Hybrid Option A: Try Groq Llama 3.3 70B with exact telemetry facts + deterministic pit verification
        $deterministic = $this->resolveDeterministicIntent($callsign, $transcript, $facts, $raceCtx);

        if ($groqKey !== null) {
            $llmResult = $this->askGroqRaceEngineer($callsign, $transcript, $facts, $pendingPit, $groqKey);
            if ($llmResult !== null) {
                // Merge pit commands: if LLM returned valid pit commands use them, else preserve deterministic explicit pit commands
                $cmds = $llmResult['pit_commands'] !== []
                    ? $llmResult['pit_commands']
                    : $deterministic['pit_commands'];

                if ($cmds !== [] && $session !== null && isset($activePlan['_pending_pit'])) {
                    unset($activePlan['_pending_pit']);
                    $session->update(['active_coaching_plan' => $activePlan !== [] ? $activePlan : null]);
                }

                return $this->formatOutput($transcript, $llmResult['reply_text'], $cmds, $radio);
            }
        }

        if ($deterministic['pit_commands'] !== [] && $session !== null && isset($activePlan['_pending_pit'])) {
            unset($activePlan['_pending_pit']);
            $session->update(['active_coaching_plan' => $activePlan !== [] ? $activePlan : null]);
        }

        return $this->formatOutput($transcript, $deterministic['reply_text'], $deterministic['pit_commands'], $radio);
    }

    private function transcribeWithGroqWhisper(string $wavBytes, string $apiKey): string
    {
        $baseUrl = rtrim((string) config('pacefriend.groq.base_url', 'https://api.groq.com/openai/v1'), '/');
        $model = (string) config('pacefriend.groq.stt_model', 'whisper-large-v3-turbo');

        try {
            $resp = Http::timeout(4)
                ->withToken($apiKey)
                ->attach('file', $wavBytes, 'radio_ptt.wav', ['Content-Type' => 'audio/wav'])
                ->post("{$baseUrl}/audio/transcriptions", [
                    'model' => $model,
                    'language' => 'es',
                    'prompt' => 'Radio de iRacing en español: gasolina, litros, ruedas, neumáticos, slicks, lluvia, agua, parada, box, ritmo, delante, detrás, incidentes, confirma.',
                ]);

            if ($resp->successful()) {
                return trim((string) $resp->json('text', ''));
            }
        } catch (\Throwable) {
            // Fall through cleanly
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>|null  $pendingPit
     * @return array{reply_text: string, pit_commands: array<int, array{action: string, value: int}>}|null
     */
    private function askGroqRaceEngineer(
        string $callsign,
        string $transcript,
        array $facts,
        ?array $pendingPit,
        string $apiKey
    ): ?array {
        $baseUrl = rtrim((string) config('pacefriend.groq.base_url', 'https://api.groq.com/openai/v1'), '/');
        $model = (string) config('pacefriend.groq.chat_model', 'llama-3.3-70b-versatile');

        $systemPrompt = <<<PROMPT
Eres el Ingeniero Jefe de Muro en iRacing de {$callsign}. Responde SIEMPRE en JSON estricto con este esquema exacto:
{"reply_text": "respuesta hablada en español de España, directa, estilo F1, máximo 25 palabras", "pit_commands": [{"action": "...", "value": 0}]}

Acciones válidas en pit_commands (solo incluye acciones si el piloto pide configurar/cambiar algo del box o confirma una propuesta):
- "clear_tires" (value: 0) -> no cambiar ruedas / desmarcar gomas
- "all_tires" (value: 0) -> cambiar las 4 ruedas
- "front_tires" | "rear_tires" | "left_tires" | "right_tires" (value: 0)
- "compound" (value: 0 para seco/slicks, value: 1 para lluvia/agua)
- "fuel" (value: litros enteros a añadir; usa refuel_to_finish_l de los datos si pide gasolina para llegar)
- "clear_fuel" (value: 0) -> no echar gasolina
- "fast_repair" (value: 0) | "clear_fr" (value: 0)
- "tearoff" (value: 0) | "clear_all" (value: 0)
Si solo hace una pregunta informativa, devuelve "pit_commands": []. Usa cifras exactas del JSON de telemetría.
PROMPT;

        $userPayload = json_encode([
            'driver_message' => $transcript,
            'telemetry_facts' => $facts,
            'pending_pit_proposal' => $pendingPit,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $resp = Http::timeout(4)
                ->withToken($apiKey)
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'temperature' => 0.15,
                    'max_tokens' => 200,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => (string) $userPayload],
                    ],
                ]);

            if (! $resp->successful()) {
                return null;
            }

            $rawContent = (string) $resp->json('choices.0.message.content', '');
            $decoded = json_decode($rawContent, true);
            if (! is_array($decoded) || empty(trim((string) ($decoded['reply_text'] ?? '')))) {
                return null;
            }

            return [
                'reply_text' => trim((string) $decoded['reply_text']),
                'pit_commands' => $this->sanitizePitCommands($decoded['pit_commands'] ?? []),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Local deterministic Spanish Race Engineer NLU + Telemetry Q&A engine (0 ms latency, 0 € cost).
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $raceCtx
     * @return array{reply_text: string, pit_commands: array<int, array{action: string, value: int}>}
     */
    private function resolveDeterministicIntent(string $callsign, string $transcript, array $facts, array $raceCtx): array
    {
        $low = Str::lower(Str::ascii($transcript));
        $cmds = [];
        $confirmParts = [];

        // --- A. Parse explicit Pit-Box Modification Commands ---
        if (preg_match('/(limpia todo|borra todo|desmarca todo)/', $low)) {
            $cmds[] = ['action' => 'clear_all', 'value' => 0];
            $confirmParts[] = 'todo el box desmarcado';
        } else {
            // Compound & Tyres
            $wantsWet = (bool) preg_match('/(pon|prepara|marca|monta|cambia).*(agua|lluvia|mojado|wet)|(ruedas|gomas|neumaticos) de (agua|lluvia|mojado)/', $low);
            $wantsDry = (bool) preg_match('/(pon|prepara|marca|monta|cambia).*(seco|slick)|(ruedas|gomas|neumaticos) de seco|pon slicks/', $low);
            $noTyres = (bool) preg_match('/(no|sin|quita|desmarca).*(cambies|cambiar|ruedas|gomas|neumaticos)/', $low);

            if ($noTyres) {
                $cmds[] = ['action' => 'clear_tires', 'value' => 0];
                $confirmParts[] = 'ruedas desmarcadas';
            } elseif ($wantsWet) {
                $cmds[] = ['action' => 'compound', 'value' => 1];
                $cmds[] = ['action' => 'all_tires', 'value' => 0];
                $confirmParts[] = 'neumáticos de lluvia marcados';
            } elseif ($wantsDry) {
                $cmds[] = ['action' => 'compound', 'value' => 0];
                $cmds[] = ['action' => 'all_tires', 'value' => 0];
                $confirmParts[] = 'slicks de seco marcados';
            } elseif (preg_match('/solo.*delanteras|cambia.*delanteras/', $low)) {
                $cmds[] = ['action' => 'front_tires', 'value' => 0];
                $confirmParts[] = 'solo ruedas delanteras';
            } elseif (preg_match('/solo.*traseras|cambia.*traseras/', $low)) {
                $cmds[] = ['action' => 'rear_tires', 'value' => 0];
                $confirmParts[] = 'solo ruedas traseras';
            } elseif (preg_match('/solo.*izquierdas|lado izquierdo/', $low)) {
                $cmds[] = ['action' => 'left_tires', 'value' => 0];
                $confirmParts[] = 'solo ruedas izquierdas';
            } elseif (preg_match('/solo.*derechas|lado derecho/', $low)) {
                $cmds[] = ['action' => 'right_tires', 'value' => 0];
                $confirmParts[] = 'solo ruedas derechas';
            } elseif (preg_match('/(cambia|marca|pon).*(cuatro|4|todas).*(ruedas|gomas)|cambia ruedas/', $low)) {
                $cmds[] = ['action' => 'all_tires', 'value' => 0];
                $confirmParts[] = 'las cuatro ruedas marcadas';
            }

            // Fuel commands
            if (preg_match('/(no|sin|quita|desmarca).*(gasolina|combustible|repostar|litros)/', $low)) {
                $cmds[] = ['action' => 'clear_fuel', 'value' => 0];
                $confirmParts[] = 'sin repostaje';
            } elseif (preg_match('/(\d{1,3})\s*(litros|l\b)/', $low, $m)) {
                $liters = max(1, min(150, (int) $m[1]));
                $cmds[] = ['action' => 'fuel', 'value' => $liters];
                $confirmParts[] = "{$liters} litros";
            } elseif (preg_match('/(gasolina|combustible|caldo|echa|ponme|meteme).*(llegar|acabar|final|meta|justa)/', $low)) {
                $refuelL = (int) ($facts['refuel_to_finish_l'] ?? 0);
                if ($refuelL > 0) {
                    $cmds[] = ['action' => 'fuel', 'value' => $refuelL];
                    $confirmParts[] = "{$refuelL} litros para llegar a meta";
                } else {
                    $cmds[] = ['action' => 'clear_fuel', 'value' => 0];
                    $confirmParts[] = 'no necesitas añadir gasolina para llegar';
                }
            }

            // Fast repair & windshield
            if (preg_match('/(quita|sin|desmarca).*(reparacion|fast repair)/', $low)) {
                $cmds[] = ['action' => 'clear_fr', 'value' => 0];
                $confirmParts[] = 'sin reparación rápida';
            } elseif (preg_match('/(reparacion rapida|fast repair)/', $low)) {
                $cmds[] = ['action' => 'fast_repair', 'value' => 0];
                $confirmParts[] = 'reparación rápida activada';
            }

            if (preg_match('/(limpia.*cristal|limpia.*luna|parabrisas|tear\s*off)/', $low)) {
                $cmds[] = ['action' => 'tearoff', 'value' => 0];
                $confirmParts[] = 'limpieza de cristal marcada';
            }
        }

        if ($cmds !== []) {
            return [
                'reply_text' => "Hecho {$callsign}: ".implode(', ', $confirmParts).' en el box.',
                'pit_commands' => $cmds,
            ];
        }

        // --- B. Answer Natural Telemetry & Strategy Questions ---
        // 1. Weather / Rain / Crossover
        if (preg_match('/(lluvia|llover|agua|tiempo|clima|meteo|mojado|seco|crossover|slicks)/', $low)) {
            $precipPct = (int) round(((float) ($raceCtx['precipitation'] ?? 0.0)) * 100);
            $wetLv = (int) ($raceCtx['track_wetness_level'] ?? 0);
            $wetAvg = (int) ($raceCtx['wet_cars_avg_lap_ms'] ?? 0);
            $dryAvg = (int) ($raceCtx['dry_cars_avg_lap_ms'] ?? 0);
            $tTrack = (int) round((float) ($facts['track_temp_c'] ?? 25));

            if ($precipPct === 0 && $wetLv === 0) {
                return [
                    'reply_text' => "Pista totalmente seca a {$tTrack} grados, cero lluvia en radar.",
                    'pit_commands' => [],
                ];
            }
            if ($wetAvg > 0 && $dryAvg > 0) {
                if ($wetAvg < $dryAvg - 500) {
                    $diff = number_format(($dryAvg - $wetAvg) / 1000.0, 1, ',', '');

                    return [
                        'reply_text' => "Lluvia al {$precipPct} por ciento. Los neumáticos de agua ya van {$diff} segundos más rápido.",
                        'pit_commands' => [],
                    ];
                }
                $diff = number_format(($wetAvg - $dryAvg) / 1000.0, 1, ',', '');

                return [
                    'reply_text' => "Chispea al {$precipPct} por ciento, humedad nivel {$wetLv}, pero los slicks siguen siendo {$diff} segundos más rápidos.",
                    'pit_commands' => [],
                ];
            }

            return [
                'reply_text' => "Precipitación al {$precipPct} por ciento y nivel de agua en pista {$wetLv} sobre 6.",
                'pit_commands' => [],
            ];
        }

        // 2. Fuel / Laps remaining / Saving
        if (preg_match('/(gasolina|combustible|litros|llego|ahorr|parar|parada|vueltas quedan|cuanto queda)/', $low)) {
            $fuelL = number_format((float) ($facts['fuel_level_l'] ?? 0.0), 1, ',', '');
            $fuelLaps = number_format((float) ($facts['fuel_laps_capacity'] ?? 0.0), 1, ',', '');
            $lapsRem = $facts['laps_remain'];
            $refuelL = (int) ($facts['refuel_to_finish_l'] ?? 0);

            if ($lapsRem !== null) {
                if ($refuelL <= 0) {
                    return [
                        'reply_text' => "Llevas {$fuelL} litros, da para {$fuelLaps} vueltas y quedan {$lapsRem}. Llegas sin parar.",
                        'pit_commands' => [],
                    ];
                }
                $shortfall = (float) ($facts['fuel_shortfall_l'] ?? 9.9);
                if ($shortfall <= 1.8 && $lapsRem >= 4) {
                    $save = number_format($shortfall / $lapsRem, 2, ',', '');

                    return [
                        'reply_text' => "Quedan {$lapsRem} vueltas y tienes para {$fuelLaps}. Ahorrando {$save} litros por vuelta llegas sin parar; si entras, son {$refuelL} litros.",
                        'pit_commands' => [],
                    ];
                }

                return [
                    'reply_text' => "Tienes {$fuelL} litros para {$fuelLaps} vueltas de {$lapsRem} restantes. Necesitas repostar {$refuelL} litros.",
                    'pit_commands' => [],
                ];
            }

            return [
                'reply_text' => "Llevas {$fuelL} litros a bordo, margen para unas {$fuelLaps} vueltas.",
                'pit_commands' => [],
            ];
        }

        // 3. Rival ahead / behind / position / pace
        if (preg_match('/(delante|detras|rival|posicion|ritmo|puesto|donde voy|como vamos)/', $low)) {
            $pos = (int) ($raceCtx['class_position'] ?? 0);
            $gapA = (float) ($raceCtx['gap_ahead_s'] ?? 0.0);
            $gapB = (float) ($raceCtx['gap_behind_s'] ?? 0.0);
            $aheadMs = (int) ($raceCtx['ahead_last_lap_ms'] ?? 0);
            $myLastMs = (int) ($facts['last_lap_ms'] ?? 0);

            $parts = [];
            if ($pos > 0) {
                $parts[] = "Vas P{$pos}.";
            }
            if ($gapA > 0) {
                $ga = number_format($gapA, 1, ',', '');
                if ($aheadMs > 0 && $myLastMs > 0) {
                    $delta = round(($aheadMs - $myLastMs) / 1000.0, 1);
                    $trend = $delta >= 0.1
                        ? 'le recortas '.number_format($delta, 1, ',', '').' por vuelta'
                        : ($delta <= -0.1 ? 'se escapa '.number_format(abs($delta), 1, ',', '').' por vuelta' : 'mismo ritmo');
                    $parts[] = "Delante a {$ga} segundos, {$trend}.";
                } else {
                    $parts[] = "Coche de delante a {$ga} segundos.";
                }
            }
            if ($gapB > 0) {
                $gb = number_format($gapB, 1, ',', '');
                $parts[] = "Detrás a {$gb}.";
            }

            return [
                'reply_text' => $parts !== [] ? implode(' ', $parts) : "Ruedas limpio {$callsign}, mantén este ritmo.",
                'pit_commands' => [],
            ];
        }

        // 4. Incidents (x)
        if (preg_match('/(incidente|puntos|x\b|sancion|limite)/', $low)) {
            $inc = (int) ($raceCtx['incident_count'] ?? 0);
            $lim = (int) ($raceCtx['incident_limit'] ?? 0);
            $txt = $lim > 0
                ? "Llevas {$inc} incidentes sobre un límite de {$lim}."
                : "Llevas {$inc} incidentes acumulados.";

            return ['reply_text' => $txt, 'pit_commands' => []];
        }

        // 5. Current Pit Box status
        if (preg_match('/(box|marcado|preparado|ruedas llevo)/', $low)) {
            $svFlags = (int) ($raceCtx['pit_sv_flags'] ?? 0);
            $svFuel = (int) round((float) ($raceCtx['pit_sv_fuel_l'] ?? 0.0));
            $tyresChecked = ($svFlags & 0x0F) !== 0;
            $fuelChecked = ($svFlags & 0x10) !== 0;
            $tStr = $tyresChecked ? 'cambio de ruedas marcado' : 'ruedas desmarcadas';
            $fStr = $fuelChecked ? "{$svFuel} litros marcados" : 'sin gasolina marcada';

            return [
                'reply_text' => "En tu box tienes {$tStr} y {$fStr}.",
                'pit_commands' => [],
            ];
        }

        return [
            'reply_text' => "Te copio {$callsign}. Dime si quieres ajustar gasolina, ruedas, compuesto o consultar huecos y meteo.",
            'pit_commands' => [],
        ];
    }

    private function classifyConfirmationIntent(string $transcript): ?string
    {
        $low = trim(Str::lower(Str::ascii($transcript)));
        if (preg_match('/^(si|vale|ok|okay|confirmo|confirma|confirmado|adelante|hazlo|preparalo|perfecto|eso es|de acuerdo|venga)\b/', $low)) {
            return 'confirm';
        }
        if (preg_match('/^(no\b|negativo|cancela|cancelalo|dejalo|aguanto|nada)/', $low)) {
            return 'reject';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raceCtx
     * @return array<string, mixed>
     */
    private function buildLiveStrategicFacts(?DrivingSession $session, ?Track $track, array $raceCtx): array
    {
        $lastLap = $session !== null
            ? Lap::query()->where('driving_session_id', $session->id)->where('is_complete', true)->latest('id')->first()
            : null;

        $avgFuel = $session !== null
            ? Lap::query()
                ->where('driving_session_id', $session->id)
                ->where('is_complete', true)
                ->where('is_clean', true)
                ->whereNotNull('fuel_used_l')
                ->where('fuel_used_l', '>', 0.15)
                ->avg('fuel_used_l')
            : null;

        $effFuelPerLap = $avgFuel !== null
            ? round((float) $avgFuel, 3)
            : (($lastLap?->fuel_used_l !== null && $lastLap->fuel_used_l > 0.15)
                ? (float) $lastLap->fuel_used_l
                : max(1.6, round(($track?->length_km ?? 5.0) * 0.50, 2)));

        $estLapMs = $session?->best_lap_time_ms ?? $lastLap?->lap_time_ms ?? (int) round((($track?->length_km ?? 5.0) * 1000.0 / 48.0) * 1000.0);
        $lapsRemain = $this->raceStrategy->estimateRemainingLaps($raceCtx, $estLapMs);
        $fuelLevelL = (float) ($raceCtx['fuel_level_l'] ?? $lastLap?->fuel_level_l ?? 0.0);
        $fuelCapacityLaps = $effFuelPerLap > 0.1 ? round($fuelLevelL / $effFuelPerLap, 2) : 0.0;
        $refuelL = $this->raceStrategy->computeRefuelLiters($lapsRemain, $effFuelPerLap, $fuelLevelL);
        $shortfallL = $lapsRemain !== null ? round(max(0.0, ($lapsRemain * $effFuelPerLap) - $fuelLevelL), 2) : 0.0;

        return [
            'session_type' => $raceCtx['session_type'] ?? 'practice',
            'class_position' => $raceCtx['class_position'] ?? null,
            'gap_ahead_s' => $raceCtx['gap_ahead_s'] ?? null,
            'ahead_last_lap_ms' => $raceCtx['ahead_last_lap_ms'] ?? null,
            'gap_behind_s' => $raceCtx['gap_behind_s'] ?? null,
            'behind_last_lap_ms' => $raceCtx['behind_last_lap_ms'] ?? null,
            'last_lap_ms' => $lastLap?->lap_time_ms,
            'best_lap_ms' => $session?->best_lap_time_ms,
            'fuel_level_l' => round($fuelLevelL, 2),
            'fuel_per_lap_l' => $effFuelPerLap,
            'fuel_laps_capacity' => $fuelCapacityLaps,
            'laps_remain' => $lapsRemain,
            'fuel_shortfall_l' => $shortfallL,
            'refuel_to_finish_l' => $refuelL,
            'incident_count' => $raceCtx['incident_count'] ?? 0,
            'incident_limit' => $raceCtx['incident_limit'] ?? 0,
            'precipitation_pct' => (int) round(((float) ($raceCtx['precipitation'] ?? 0.0)) * 100),
            'track_wetness_level' => $raceCtx['track_wetness_level'] ?? 0,
            'weather_declared_wet' => ! empty($raceCtx['weather_declared_wet']),
            'player_compound' => ((int) ($raceCtx['player_compound'] ?? 0)) >= 1 ? 'wet' : 'dry',
            'dry_cars_avg_lap_ms' => $raceCtx['dry_cars_avg_lap_ms'] ?? null,
            'wet_cars_avg_lap_ms' => $raceCtx['wet_cars_avg_lap_ms'] ?? null,
            'track_temp_c' => $raceCtx['track_temp_c'] ?? $session?->avg_track_temp_c ?? 25.0,
        ];
    }

    /**
     * @param  mixed  $rawCommands
     * @return array<int, array{action: string, value: int}>
     */
    private function sanitizePitCommands(mixed $rawCommands): array
    {
        if (! is_array($rawCommands)) {
            return [];
        }

        $clean = [];
        foreach ($rawCommands as $cmd) {
            if (! is_array($cmd)) {
                continue;
            }
            $action = strtolower(trim((string) ($cmd['action'] ?? '')));
            if (! in_array($action, self::ALLOWED_PIT_ACTIONS, true)) {
                continue;
            }
            $clean[] = [
                'action' => $action,
                'value' => max(0, min(250, (int) ($cmd['value'] ?? 0))),
            ];
        }

        return $clean;
    }

    /**
     * @param  array<int, array{action: string, value: int}>  $pitCommands
     * @param  array<string, mixed>  $radio
     * @return array{
     *   transcript: string,
     *   reply_text: string,
     *   radio_callouts: array<int, array{id: string, text: string, audio_url: string, audio_sha256: string}>,
     *   pit_commands: array<int, array{action: string, value: int}>
     * }
     */
    private function formatOutput(string $transcript, string $replyText, array $pitCommands, array $radio): array
    {
        $clip = $this->voiceManager->ensureAudioClip($replyText, $radio);

        return [
            'transcript' => $transcript,
            'reply_text' => $replyText,
            'radio_callouts' => [[
                'id' => 'voice_reply_'.substr($clip['sha256'], 0, 8),
                'text' => $replyText,
                'audio_url' => $clip['url'],
                'audio_sha256' => $clip['sha256'],
            ]],
            'pit_commands' => $pitCommands,
        ];
    }
}
