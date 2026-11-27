<?php

namespace App\Services\Reports;

use App\Models\DrivingSession;
use App\Models\Lap;
use App\Models\SessionReport;
use App\Services\Telemetry\TelemetryNormalizerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProSessionReportService
{
    public function __construct(
        private readonly TelemetryNormalizerService $normalizer,
    ) {}

    /**
     * Build or regenerate an on-demand PRO post-session report quantifying both
     * intrasesión driver improvements and high-return improvement areas.
     */
    public function generateForSession(DrivingSession $session): SessionReport
    {
        $session->loadMissing(['user', 'track', 'activeReferenceLap', 'laps']);

        $cleanLaps = $session->laps
            ->where('is_clean', true)
            ->values();

        $summary = $this->computeQuantitativeSummary($session, $cleanLaps->all());
        $priorities = $this->computePriorityAreas($session, $cleanLaps->all());

        [$driverUsed, $markdown] = $this->narrateReport(
            $session->user->effectiveCallsign(),
            $session,
            $summary,
            $priorities
        );

        return SessionReport::query()->updateOrCreate(
            ['driving_session_id' => $session->id],
            [
                'user_id' => $session->user_id,
                'reference_lap_id' => $session->active_reference_lap_id,
                'status' => 'ready',
                'llm_driver' => $driverUsed,
                'quantitative_summary' => $summary,
                'priority_areas' => $priorities,
                'narrative_markdown' => $markdown,
                'generated_at' => now(),
            ]
        );
    }

    /**
     * @param  array<int, Lap>  $cleanLaps
     * @return array<string, mixed>
     */
    private function computeQuantitativeSummary(DrivingSession $session, array $cleanLaps): array
    {
        $totalLaps = $session->laps->count();
        $cleanCount = count($cleanLaps);

        if ($cleanCount === 0) {
            return [
                'total_laps' => $totalLaps,
                'clean_laps' => 0,
                'best_lap_ms' => $session->best_lap_time_ms,
                'reference_lap_ms' => $session->activeReferenceLap?->lap_time_ms,
                'pace_gain_ms' => 0,
                'consistency_early_ms' => 0,
                'consistency_late_ms' => 0,
                'improved_corners' => [],
            ];
        }

        $times = array_map(static fn (Lap $l) => $l->lap_time_ms, $cleanLaps);
        $bestLapMs = min($times);
        $avgLapMs = (int) round(array_sum($times) / $cleanCount);

        $chunkSize = max(1, intdiv($cleanCount, 2));
        $earlyLaps = array_slice($cleanLaps, 0, $chunkSize);
        $lateLaps = array_slice($cleanLaps, -$chunkSize);

        $earlyAvg = (int) round(array_sum(array_map(static fn (Lap $l) => $l->lap_time_ms, $earlyLaps)) / count($earlyLaps));
        $lateAvg = (int) round(array_sum(array_map(static fn (Lap $l) => $l->lap_time_ms, $lateLaps)) / count($lateLaps));
        $paceGainMs = $earlyAvg - $lateAvg; // positive = got faster during the session!

        $earlyStd = $this->stdDevMs($earlyLaps);
        $lateStd = $this->stdDevMs($lateLaps);

        // Identify corners where the driver genuinely improved between early stint and late stint
        $earlyCorners = $this->averageCornerTimes($earlyLaps);
        $lateCorners = $this->averageCornerTimes($lateLaps);
        $improvedCorners = [];

        foreach ($earlyCorners as $turn => $earlyData) {
            if (! isset($lateCorners[$turn])) {
                continue;
            }
            $gainMs = (int) round($earlyData['segment_time_ms'] - $lateCorners[$turn]['segment_time_ms']);
            if ($gainMs >= 35) {
                $improvedCorners[] = [
                    'turn' => $turn,
                    'name' => $earlyData['name'],
                    'gain_ms' => $gainMs,
                    'early_ms' => (int) round($earlyData['segment_time_ms']),
                    'late_ms' => (int) round($lateCorners[$turn]['segment_time_ms']),
                ];
            }
        }

        usort($improvedCorners, static fn (array $a, array $b) => $b['gain_ms'] <=> $a['gain_ms']);

        return [
            'total_laps' => $totalLaps,
            'clean_laps' => $cleanCount,
            'best_lap_ms' => $bestLapMs,
            'avg_lap_ms' => $avgLapMs,
            'early_stint_avg_ms' => $earlyAvg,
            'late_stint_avg_ms' => $lateAvg,
            'pace_gain_ms' => $paceGainMs,
            'consistency_early_ms' => $earlyStd,
            'consistency_late_ms' => $lateStd,
            'reference_lap_ms' => $session->activeReferenceLap?->lap_time_ms,
            'reference_track_temp_c' => $session->activeReferenceLap?->track_temp_c,
            'session_track_temp_c' => $session->avg_track_temp_c,
            'improved_corners' => array_slice($improvedCorners, 0, 3),
        ];
    }

    /**
     * @param  array<int, Lap>  $cleanLaps
     * @return array<int, array<string, mixed>>
     */
    private function computePriorityAreas(DrivingSession $session, array $cleanLaps): array
    {
        $ref = $session->activeReferenceLap;
        if ($ref === null || $cleanLaps === []) {
            return [];
        }

        $recent = array_slice($cleanLaps, -min(5, count($cleanLaps)));
        $refBaselines = $ref->corner_baselines ?? [];
        $byTurn = [];

        foreach ($recent as $lap) {
            $compared = $this->normalizer->compareAgainstReference(
                $lap->corner_metrics ?? [],
                $refBaselines,
                $session->track->length_km
            );
            foreach ($compared as $cm) {
                $turn = (int) ($cm['turn'] ?? 0);
                if ($turn <= 0 || ! isset($cm['time_loss_ms'])) {
                    continue;
                }
                $byTurn[$turn] ??= [
                    'turn' => $turn,
                    'name' => (string) ($cm['name'] ?? "Curva {$turn}"),
                    'losses' => [],
                    'early_brakes' => [],
                    'late_throttles' => [],
                    'apex_deltas' => [],
                    'entry_insides' => [],
                    'apex_insides' => [],
                    'exit_insides' => [],
                    'understeers' => [],
                ];
                $byTurn[$turn]['losses'][] = (int) $cm['time_loss_ms'];
                $byTurn[$turn]['early_brakes'][] = (float) ($cm['early_brake_m'] ?? 0.0);
                $byTurn[$turn]['late_throttles'][] = (float) ($cm['late_throttle_m'] ?? 0.0);
                $byTurn[$turn]['apex_deltas'][] = (float) ($cm['apex_speed_delta_kmh'] ?? 0.0);
                if (isset($cm['entry_inside_m'], $cm['apex_inside_m'], $cm['exit_inside_m'])) {
                    $byTurn[$turn]['entry_insides'][] = (float) $cm['entry_inside_m'];
                    $byTurn[$turn]['apex_insides'][] = (float) $cm['apex_inside_m'];
                    $byTurn[$turn]['exit_insides'][] = (float) $cm['exit_inside_m'];
                }
                if (isset($cm['understeer_deg'])) {
                    $byTurn[$turn]['understeers'][] = (float) $cm['understeer_deg'];
                }
            }
        }

        $priorities = [];
        foreach ($byTurn as $item) {
            $avgLoss = (int) round(array_sum($item['losses']) / count($item['losses']));
            if ($avgLoss <= 30) {
                continue;
            }
            $earlyBrake = round(array_sum($item['early_brakes']) / count($item['early_brakes']), 1);
            $lateThrottle = round(array_sum($item['late_throttles']) / count($item['late_throttles']), 1);
            $apexDelta = round(array_sum($item['apex_deltas']) / count($item['apex_deltas']), 1);
            $entryInside = $item['entry_insides'] !== [] ? round(array_sum($item['entry_insides']) / count($item['entry_insides']), 2) : null;
            $apexInside = $item['apex_insides'] !== [] ? round(array_sum($item['apex_insides']) / count($item['apex_insides']), 2) : null;
            $exitInside = $item['exit_insides'] !== [] ? round(array_sum($item['exit_insides']) / count($item['exit_insides']), 2) : null;
            $understeer = $item['understeers'] !== [] ? round(array_sum($item['understeers']) / count($item['understeers']), 1) : 0.0;

            $priorities[] = [
                'turn' => $item['turn'],
                'name' => $item['name'],
                'avg_time_loss_ms' => $avgLoss,
                'early_brake_m' => $earlyBrake,
                'late_throttle_m' => $lateThrottle,
                'apex_speed_delta_kmh' => $apexDelta,
                'entry_inside_m' => $entryInside,
                'apex_inside_m' => $apexInside,
                'exit_inside_m' => $exitInside,
                'understeer_deg' => $understeer,
                'clear_advice' => $this->explainCornerPlainly($item['name'], $earlyBrake, $apexDelta, $lateThrottle, $entryInside, $apexInside, $exitInside, $understeer),
            ];
        }

        usort($priorities, static fn (array $a, array $b) => $b['avg_time_loss_ms'] <=> $a['avg_time_loss_ms']);

        return array_slice($priorities, 0, 3);
    }

    private function explainCornerPlainly(
        string $name,
        float $earlyBrakeM,
        float $apexDeltaKmh,
        float $lateThrottleM,
        ?float $entryInsideM = null,
        ?float $apexInsideM = null,
        ?float $exitInsideM = null,
        float $understeerDeg = 0.0
    ): string {
        if ($earlyBrakeM <= -7.0 && (($apexInsideM !== null && $apexInsideM <= -0.85) || $lateThrottleM >= 4.0)) {
            $m = (int) round(abs($earlyBrakeM));
            return "Causa raíz en frenada: retrasas {$m} m de más el punto de frenada en {$name}, lo que te hace pasarte del vértice y retrasar la tracción. Frena un punto antes para ceñir el morro.";
        }
        if ($entryInsideM !== null && $entryInsideM >= 1.15) {
            return "Causa raíz en trazada de entrada: entras {$entryInsideM} m más cerrado que la referencia en {$name}. Ábrete al piano exterior antes de girar para ganar radio de curva.";
        }
        if ($earlyBrakeM >= 8.0) {
            $m = (int) round($earlyBrakeM);
            return "Estás iniciando la frenada unos {$m} m antes que la referencia de igual temperatura. Mantén el gas a fondo un instante más y ataca el pedal con decisión inicial.";
        }
        if ($understeerDeg >= 18.0) {
            return "Exceso de volante (+{$understeerDeg}° extra respecto a la rotación conseguida) en {$name}: estás arrastrando el neumático delantero. Suaviza manos y apóyate en el freno regresivo.";
        }
        if ($apexInsideM !== null && $apexInsideM <= -1.05) {
            $miss = abs($apexInsideM);
            return "Pierdes el vértice de {$name} por {$miss} m hacia el exterior. Mantén un hilo de freno suave hasta apuntar al piano interior.";
        }
        if ($exitInsideM !== null && $exitInsideM >= 1.30) {
            return "Salida ahogada en {$name}: te dejas {$exitInsideM} m de pista exterior sin usar. Abre dirección antes desde el vértice para no frenar el coche con las manos.";
        }
        if ($apexDeltaKmh <= -5.0) {
            $kmh = abs(round($apexDeltaKmh));
            return "Llegas al vértice de {$name} unos {$kmh} km/h más lento por mantener presión de freno demasiado tiempo al girar. Libera freno progresivamente al meter volante.";
        }
        if ($lateThrottleM >= 8.0) {
            $m = (int) round($lateThrottleM);
            return "Retrasas {$m} m la apertura total del acelerador al salir de {$name}. Con el coche ya rotado en el vértice, confía y aplica gas antes.";
        }

        return "Suaviza la transición entre soltar freno y dar el primer toque de acelerador para mantener asentada la plataforma aerodinámica en {$name}.";
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $priorities
     * @return array{0: string, 1: string}
     */
    private function narrateReport(
        string $callsign,
        DrivingSession $session,
        array $summary,
        array $priorities
    ): array {
        $driver = (string) config('pacefriend.llm.driver', 'local');

        if ($driver === 'gemini' && filled(config('pacefriend.llm.gemini.api_key'))) {
            $md = $this->callGeminiNarrator($callsign, $session, $summary, $priorities);
            if ($md !== null) {
                return ['gemini', $md];
            }
        }

        if ($driver === 'openai' && filled(config('pacefriend.llm.openai.api_key'))) {
            $md = $this->callOpenAiNarrator($callsign, $session, $summary, $priorities);
            if ($md !== null) {
                return ['openai', $md];
            }
        }

        return ['local', $this->buildStructuredEngineerReport($callsign, $session, $summary, $priorities)];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $priorities
     */
    private function callGeminiNarrator(string $callsign, DrivingSession $session, array $summary, array $priorities): ?string
    {
        try {
            $model = (string) config('pacefriend.llm.gemini.model', 'gemini-2.5-flash');
            $apiKey = (string) config('pacefriend.llm.gemini.api_key');
            $prompt = $this->buildLlmPrompt($callsign, $session, $summary, $priorities);

            $resp = Http::timeout(20)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                ]
            );

            if ($resp->successful()) {
                return $resp->json('candidates.0.content.parts.0.text');
            }
        } catch (\Throwable $e) {
            Log::warning('Gemini report narrator fallback', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $priorities
     */
    private function callOpenAiNarrator(string $callsign, DrivingSession $session, array $summary, array $priorities): ?string
    {
        try {
            $resp = Http::withToken((string) config('pacefriend.llm.openai.api_key'))
                ->timeout(20)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => (string) config('pacefriend.llm.openai.model', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'Eres un ingeniero jefe de pista de GT3/Fórmula. Hablas claro, motivador y sin tecnicismos confusos.'],
                        ['role' => 'user', 'content' => $this->buildLlmPrompt($callsign, $session, $summary, $priorities)],
                    ],
                ]);

            if ($resp->successful()) {
                return $resp->json('choices.0.message.content');
            }
        } catch (\Throwable $e) {
            Log::warning('OpenAI report narrator fallback', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $priorities
     */
    private function buildLlmPrompt(string $callsign, DrivingSession $session, array $summary, array $priorities): string
    {
        $payload = json_encode([
            'piloto' => $callsign,
            'circuito' => $session->track->name,
            'coche' => $session->car_name,
            'resumen' => $summary,
            'areas_prioritarias' => $priorities,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return "Redacta en Markdown un Informe PRO post-sesión dirigiéndote a {$callsign} como su ingeniero de pista. "
            ."Usa exclusivamente estos datos verificados, destaca primero las mejoras conseguidas durante la sesión y luego explica de forma cristalina y sin jerga críptica los 3 puntos prioritarios a mejorar:\n\n{$payload}";
    }

    /**
     * High-craft local engineer debriefing generator (zero API cost, deterministic & clear).
     *
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $priorities
     */
    private function buildStructuredEngineerReport(
        string $callsign,
        DrivingSession $session,
        array $summary,
        array $priorities
    ): string {
        $bestFormatted = $this->formatLapTime((int) ($summary['best_lap_ms'] ?? 0));
        $refFormatted = $this->formatLapTime((int) ($summary['reference_lap_ms'] ?? 0));
        $gainSec = number_format(((int) ($summary['pace_gain_ms'] ?? 0)) / 1000, 2);

        $lines = [
            "# Debriefing PRO de Ingeniería — {$callsign}",
            '',
            "**Circuito:** {$session->track->name} · **Coche:** {$session->car_name} · **Asfalto medio:** {$session->avg_track_temp_c} °C",
            '',
            '## 1. Balance General de la Tanda',
            "Buen trabajo en pista, **{$callsign}**. Completaste **{$summary['clean_laps']} vueltas limpias** de {$summary['total_laps']} totales, firmando tu mejor registro en **{$bestFormatted}** (comparado contra la vuelta de referencia de **{$refFormatted}** seleccionada específicamente para condiciones de asfalto de **".($summary['reference_track_temp_c'] ?? $session->avg_track_temp_c)." °C**).",
            '',
            '## 2. Mejoras Conseguidas Durante Esta Sesión',
        ];

        if ((int) ($summary['pace_gain_ms'] ?? 0) > 0) {
            $lines[] = "- **Evolución de ritmo positiva:** Bajaste tu media por vuelta en **{$gainSec} s** entre el inicio y el cierre de la tanda.";
        } else {
            $lines[] = "- **Ritmo estabilizado:** Mantuviste una base sólida durante toda la tanda a pesar de la degradación térmica.";
        }

        $earlyStd = number_format(((int) ($summary['consistency_early_ms'] ?? 0)) / 1000, 2);
        $lateStd = number_format(((int) ($summary['consistency_late_ms'] ?? 0)) / 1000, 2);
        $lines[] = "- **Consistencia de vuelta a vuelta:** Tu dispersión pasó de **±{$earlyStd} s** al principio a **±{$lateStd} s** en el tramo final.";

        if (! empty($summary['improved_corners'])) {
            foreach ($summary['improved_corners'] as $ic) {
                $cGain = number_format($ic['gain_ms'] / 1000, 2);
                $lines[] = "- **Progreso real en {$ic['name']} (Curva {$ic['turn']}):** Recortaste **{$cGain} s** respecto a tus primeras vueltas aplicando las correcciones en pista.";
            }
        }

        $lines[] = '';
        $lines[] = '## 3. Plan de Ataque: Puntos Clave a Mejorar';

        if ($priorities === []) {
            $lines[] = 'Estás rodando muy cerca de la referencia actual en todos los sectores. Es buen momento para cargar una vuelta de referencia más exigente.';
        } else {
            foreach ($priorities as $idx => $p) {
                $rank = $idx + 1;
                $lossSec = number_format($p['avg_time_loss_ms'] / 1000, 2);
                $lines[] = "### {$rank}. {$p['name']} (Curva {$p['turn']}) — Potencial directo: **-{$lossSec} s**";
                $lines[] = $p['clear_advice'];
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, Lap>  $laps
     */
    private function stdDevMs(array $laps): int
    {
        $n = count($laps);
        if ($n < 2) {
            return 0;
        }
        $times = array_map(static fn (Lap $l) => $l->lap_time_ms, $laps);
        $mean = array_sum($times) / $n;
        $variance = 0.0;
        foreach ($times as $t) {
            $variance += ($t - $mean) ** 2;
        }
        return (int) round(sqrt($variance / $n));
    }

    /**
     * @param  array<int, Lap>  $laps
     * @return array<int, array{name: string, segment_time_ms: float}>
     */
    private function averageCornerTimes(array $laps): array
    {
        $buckets = [];
        foreach ($laps as $lap) {
            foreach (($lap->corner_metrics ?? []) as $cm) {
                $turn = (int) ($cm['turn'] ?? 0);
                if ($turn <= 0 || ! isset($cm['segment_time_ms'])) {
                    continue;
                }
                $buckets[$turn] ??= ['name' => (string) ($cm['name'] ?? "Curva {$turn}"), 'times' => []];
                $buckets[$turn]['times'][] = (int) $cm['segment_time_ms'];
            }
        }

        $out = [];
        foreach ($buckets as $turn => $data) {
            $out[$turn] = [
                'name' => $data['name'],
                'segment_time_ms' => array_sum($data['times']) / count($data['times']),
            ];
        }
        return $out;
    }

    private function formatLapTime(int $ms): string
    {
        if ($ms <= 0) {
            return '--:--.---';
        }
        $minutes = intdiv($ms, 60000);
        $remMs = $ms % 60000;
        $seconds = intdiv($remMs, 1000);
        $millis = $remMs % 1000;
        return sprintf('%d:%02d.%03d', $minutes, $seconds, $millis);
    }
}
