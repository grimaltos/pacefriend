<?php

namespace App\Services\Coaching;

use App\Models\Lap;
use App\Models\ReferenceLap;
use App\Models\Track;
use App\Models\User;
use App\Services\Telemetry\TelemetryNormalizerService;
use App\Services\Voice\VoiceSynthesisManager;

class CoachDiagnosticService
{
    public function __construct(
        private readonly TelemetryNormalizerService $normalizer,
        private readonly VoiceSynthesisManager $voiceManager,
    ) {}

    /**
     * Evaluate the sliding window of the user's recent clean partial/corner passes
     * against the active weather-matched ReferenceLap using a chronological causal
     * model (Braking -> Entry Line -> Apex/Steering -> Traction/Exit Line).
     *
     * @return array{clean_laps_count: int, plan: array<string, mixed>|null}
     */
    public function buildCoachingPlan(
        User $user,
        Track $track,
        string $carName,
        ?ReferenceLap $referenceLap
    ): array {
        $radio = $user->radioSettings();
        $windowSize = (int) config('pacefriend.clean_laps_window', 5);
        $minPasses = (int) $radio['min_clean_laps'];

        $recentLaps = Lap::query()
            ->where('user_id', $user->id)
            ->where('track_id', $track->id)
            ->where('car_name', $carName)
            ->whereNotNull('corner_metrics')
            ->orderByDesc('id')
            ->limit($windowSize * 4)
            ->get()
            ->all();

        [$bottleneck, $maxCleanPasses] = $this->detectChronicBottleneck(
            $recentLaps,
            $track,
            $referenceLap,
            $windowSize,
            $minPasses
        );

        if ($referenceLap === null || $bottleneck === null || $maxCleanPasses < $minPasses) {
            return [
                'clean_laps_count' => $maxCleanPasses,
                'plan' => null,
            ];
        }

        $callsign = $user->spokenCallsign();
        [$preBrakeText, $postExitText] = $this->translateHabitToNaturalSpeech($callsign, $bottleneck);

        $preAudio = $this->voiceManager->ensureAudioClip($preBrakeText, $radio);
        $postAudio = $this->voiceManager->ensureAudioClip($postExitText, $radio);

        $preTriggerPct = $this->normalizer->wrapPct((float) $bottleneck['brake_pct'] - 0.040);
        $postTriggerPct = $this->normalizer->wrapPct((float) $bottleneck['exit_pct'] + 0.012);

        $planSignature = substr(hash('sha256', implode(':', [
            $user->id,
            $track->slug,
            $referenceLap->id,
            $bottleneck['turn'],
            $bottleneck['primary_issue'],
        ])), 0, 12);

        return [
            'clean_laps_count' => $maxCleanPasses,
            'plan' => [
                'plan_id' => "plan_{$track->slug}_t{$bottleneck['turn']}_{$planSignature}",
                'generated_at' => now()->toIso8601String(),
                'reference_lap_id' => $referenceLap->id,
                'reference_track_temp_c' => $referenceLap->track_temp_c,
                'focus_turn' => $bottleneck['turn'],
                'focus_turn_name' => $bottleneck['name'],
                'primary_issue' => $bottleneck['primary_issue'],
                'avg_time_loss_ms' => $bottleneck['avg_time_loss_ms'],
                'telemetry_breakdown' => $bottleneck['telemetry_breakdown'],
                'triggers' => [
                    [
                        'id' => "t{$bottleneck['turn']}_pre_brake",
                        'turn' => $bottleneck['turn'],
                        'phase' => 'pre_brake',
                        'trigger_pct' => $preTriggerPct,
                        'text' => $preBrakeText,
                        'audio_url' => $preAudio['url'],
                        'audio_sha256' => $preAudio['sha256'],
                    ],
                    [
                        'id' => "t{$bottleneck['turn']}_post_exit",
                        'turn' => $bottleneck['turn'],
                        'phase' => 'post_exit',
                        'trigger_pct' => $postTriggerPct,
                        'text' => $postExitText,
                        'audio_url' => $postAudio['url'],
                        'audio_sha256' => $postAudio['sha256'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, Lap>  $recentLaps
     * @return array{0: array<string, mixed>|null, 1: int}
     */
    private function detectChronicBottleneck(
        array $recentLaps,
        Track $track,
        ?ReferenceLap $referenceLap,
        int $windowSize,
        int $minChronicCount
    ): array {
        $refCorners = $referenceLap?->corner_baselines ?? [];
        $passesByTurn = [];

        foreach ($recentLaps as $lap) {
            $metrics = $lap->corner_metrics ?? [];
            if ($metrics === []) {
                continue;
            }

            $compared = $refCorners !== []
                ? $this->normalizer->compareAgainstReference($metrics, $refCorners, $track->length_km)
                : $metrics;

            foreach ($compared as $cm) {
                $turn = (int) ($cm['turn'] ?? 0);
                if ($turn <= 0) {
                    continue;
                }

                $isCleanPass = array_key_exists('is_clean', $cm)
                    ? (bool) $cm['is_clean']
                    : (bool) $lap->is_clean;

                if (! $isCleanPass) {
                    continue;
                }

                $passesByTurn[$turn][] = $cm;
            }
        }

        $maxCleanPasses = 0;
        $minLossThreshold = (int) config('pacefriend.min_corner_loss_ms', 80);
        $bestCandidate = null;

        foreach ($passesByTurn as $turn => $rawPasses) {
            $filtered = $this->filterCornerSegmentOutliers($rawPasses, $windowSize);
            $count = count($filtered);
            if ($count > $maxCleanPasses) {
                $maxCleanPasses = $count;
            }

            if ($referenceLap === null || $count < $minChronicCount) {
                continue;
            }

            $losses = [];
            $earlyBrakes = [];
            $lateThrottles = [];
            $apexSpeedDeltas = [];
            $exitSpeedDeltas = [];
            $apexBrakeExcesses = [];
            $entryInsides = [];
            $preApexInsides = [];
            $apexInsides = [];
            $exitInsides = [];
            $understeerDegs = [];
            $turnDir = -1;

            foreach ($filtered as $cm) {
                if (! isset($cm['time_loss_ms'])) {
                    continue;
                }
                $losses[] = (int) $cm['time_loss_ms'];
                $earlyBrakes[] = (float) ($cm['early_brake_m'] ?? 0.0);
                $lateThrottles[] = (float) ($cm['late_throttle_m'] ?? 0.0);
                $apexSpeedDeltas[] = (float) ($cm['apex_speed_delta_kmh'] ?? 0.0);
                $exitSpeedDeltas[] = (float) ($cm['exit_speed_delta_kmh'] ?? 0.0);
                $apexBrakeExcesses[] = (float) ($cm['apex_brake_excess'] ?? 0.0);

                if (isset($cm['turn_dir'])) {
                    $turnDir = (int) $cm['turn_dir'];
                }
                if (isset($cm['entry_inside_m'], $cm['apex_inside_m'], $cm['exit_inside_m'])) {
                    $entryInsides[] = (float) $cm['entry_inside_m'];
                    $preApexInsides[] = (float) ($cm['pre_apex_inside_m'] ?? 0.0);
                    $apexInsides[] = (float) $cm['apex_inside_m'];
                    $exitInsides[] = (float) $cm['exit_inside_m'];
                }
                if (isset($cm['understeer_deg'])) {
                    $understeerDegs[] = (float) $cm['understeer_deg'];
                }
            }

            $lossPasses = array_filter($losses, static fn (int $ms) => $ms >= ($minLossThreshold / 2));
            if (count($lossPasses) < $minChronicCount || $losses === []) {
                continue;
            }

            $avgLoss = (int) round(array_sum($losses) / count($losses));
            if ($avgLoss < $minLossThreshold) {
                continue;
            }

            if ($bestCandidate === null || $avgLoss > $bestCandidate['avg_time_loss_ms']) {
                $sample0 = $filtered[0];
                $avgEarlyBrakeM = array_sum($earlyBrakes) / count($earlyBrakes);
                $avgLateThrottleM = array_sum($lateThrottles) / count($lateThrottles);
                $avgApexSpeedDelta = array_sum($apexSpeedDeltas) / count($apexSpeedDeltas);
                $avgExitSpeedDelta = array_sum($exitSpeedDeltas) / count($exitSpeedDeltas);
                $avgApexBrakeExcess = array_sum($apexBrakeExcesses) / count($apexBrakeExcesses);

                $hasGeo = count($entryInsides) >= max(1, intdiv($minChronicCount, 2));
                $avgEntryInside = $hasGeo ? (array_sum($entryInsides) / count($entryInsides)) : 0.0;
                $avgPreApexInside = $hasGeo ? (array_sum($preApexInsides) / count($preApexInsides)) : 0.0;
                $avgApexInside = $hasGeo ? (array_sum($apexInsides) / count($apexInsides)) : 0.0;
                $avgExitInside = $hasGeo ? (array_sum($exitInsides) / count($exitInsides)) : 0.0;
                $avgUndersteerDeg = $understeerDegs !== [] ? (array_sum($understeerDegs) / count($understeerDegs)) : 0.0;

                $primaryIssue = $this->classifyCausalRootIssue(
                    $avgEarlyBrakeM,
                    $avgApexBrakeExcess,
                    $avgApexSpeedDelta,
                    $avgExitSpeedDelta,
                    $avgLateThrottleM,
                    $hasGeo,
                    $avgEntryInside,
                    $avgPreApexInside,
                    $avgApexInside,
                    $avgExitInside,
                    $avgUndersteerDeg
                );

                $bestCandidate = [
                    'turn' => (int) $turn,
                    'name' => (string) ($sample0['name'] ?? "Curva {$turn}"),
                    'brake_pct' => (float) ($sample0['brake_pct'] ?? 0.0),
                    'exit_pct' => (float) ($sample0['exit_pct'] ?? 0.0),
                    'turn_dir' => $turnDir,
                    'avg_time_loss_ms' => $avgLoss,
                    'avg_early_brake_m' => round($avgEarlyBrakeM, 1),
                    'avg_late_throttle_m' => round($avgLateThrottleM, 1),
                    'avg_apex_speed_delta_kmh' => round($avgApexSpeedDelta, 1),
                    'avg_apex_brake_excess' => round($avgApexBrakeExcess, 3),
                    'primary_issue' => $primaryIssue,
                    'telemetry_breakdown' => [
                        'has_trajectory' => $hasGeo,
                        'turn_dir' => $turnDir,
                        'early_brake_m' => round($avgEarlyBrakeM, 1),
                        'entry_inside_m' => round($avgEntryInside, 2),
                        'pre_apex_inside_m' => round($avgPreApexInside, 2),
                        'apex_inside_m' => round($avgApexInside, 2),
                        'exit_inside_m' => round($avgExitInside, 2),
                        'understeer_deg' => round($avgUndersteerDeg, 1),
                        'late_throttle_m' => round($avgLateThrottleM, 1),
                        'apex_speed_delta_kmh' => round($avgApexSpeedDelta, 1),
                    ],
                ];
            }
        }

        return [$bestCandidate, $maxCleanPasses];
    }

    /**
     * Chronological causal decision tree: evaluates Entry (Braking + Turn-in Width) ->
     * Mid-Corner (Early/Missed Apex + Trail-Brake + Steering Scrub) -> Exit (Throttle + Track-Out)
     * so downstream symptoms are never blamed when caused by an upstream mistake.
     */
    public function classifyCausalRootIssue(
        float $earlyBrakeM,
        float $apexBrakeExcess,
        float $apexSpeedDeltaKmh,
        float $exitSpeedDeltaKmh,
        float $lateThrottleM,
        bool $hasGeo,
        float $entryInsideM,
        float $preApexInsideM,
        float $apexInsideM,
        float $exitInsideM,
        float $understeerDeg
    ): string {
        // 1. Late braking overshoots apex and compromises exit traction
        if ($earlyBrakeM <= -7.0 && (($hasGeo && $apexInsideM <= -0.85) || $lateThrottleM >= 4.0 || $exitSpeedDeltaKmh <= -3.5)) {
            return 'overdriving_entry';
        }

        // 2. Narrow entry (not opening up to outside kerb before turning in)
        if ($hasGeo && $entryInsideM >= 1.15) {
            return 'narrow_entry';
        }

        // 3. Diving inside too early (Early Apex) -> chokes exit angle & delays throttle
        if ($hasGeo && $preApexInsideM >= 0.85 && ($lateThrottleM >= 4.5 || $exitSpeedDeltaKmh <= -2.5 || $understeerDeg >= 14.0)) {
            return 'early_apex';
        }

        // 4. Conservative early braking
        if ($earlyBrakeM >= 8.0) {
            return 'early_braking';
        }

        // 5. Impatient throttle pickup while steering lock is still high -> pushes nose wide
        if ($understeerDeg >= 18.0 && $lateThrottleM <= 3.0 && (! $hasGeo || $apexInsideM > -0.95) && ($exitSpeedDeltaKmh <= -2.0 || ($hasGeo && $exitInsideM <= -0.7))) {
            return 'throttle_understeer';
        }

        // 6. Excessive steering wheel angle scrubbing front tyres mid-corner
        if ($understeerDeg >= 18.0) {
            return 'understeer_scrub';
        }

        // 7. Missing the inside apex kerb (without having braked late)
        if ($hasGeo && $apexInsideM <= -1.05) {
            return 'missed_apex';
        }

        // 8. Holding too much brake pressure at apex / over-slowing mid-corner
        if ($apexBrakeExcess >= 0.08 || $apexSpeedDeltaKmh <= -5.5) {
            return 'overslow_apex';
        }

        // 9. Pinching the exit (not unwinding steering to use full outside exit kerb)
        if ($hasGeo && $exitInsideM >= 1.30) {
            return 'pinched_exit';
        }

        // 10. Clean line & apex, genuine hesitation on throttle application
        if ($lateThrottleM >= 9.0) {
            return 'late_throttle';
        }

        return 'minimum_speed';
    }

    /**
     * @param  array<int, array<string, mixed>>  $passes
     * @return array<int, array<string, mixed>>
     */
    private function filterCornerSegmentOutliers(array $passes, int $windowSize): array
    {
        if (count($passes) <= 2) {
            return array_slice($passes, 0, $windowSize);
        }

        $segTimes = array_map(static fn (array $p) => (int) ($p['segment_time_ms'] ?? 0), $passes);
        sort($segTimes);
        $median = max(100, $segTimes[intdiv(count($segTimes), 2)]);
        $maxAllowed = (int) round($median * 1.22);

        $valid = array_values(array_filter(
            $passes,
            static fn (array $p) => (int) ($p['segment_time_ms'] ?? 0) <= $maxAllowed
        ));

        return array_slice($valid, 0, $windowSize);
    }

    /**
     * Translate root-cause diagnostic metrics into natural Spanish race engineer callouts.
     *
     * @param  array<string, mixed>  $b
     * @return array{0: string, 1: string}
     */
    private function translateHabitToNaturalSpeech(string $callsign, array $b): array
    {
        $turnName = (string) $b['name'];
        $metersBrake = max(5, min(25, (int) (round(abs((float) $b['avg_early_brake_m']) / 5) * 5)));
        // If turn_dir is +1 (Left turn), outside kerb is on the RIGHT; if -1 (Right turn), outside kerb is on the LEFT!
        $outsideSide = ((int) ($b['turn_dir'] ?? -1)) > 0 ? 'derecha' : 'izquierda';

        return match ($b['primary_issue']) {
            'narrow_entry' => [
                "{$callsign}, en {$turnName} ábrete bien a la {$outsideSide} antes de frenar para ganar ángulo.",
                'Aprovechar todo el exterior al entrar te permite pasar con más velocidad por el vértice.',
            ],
            'early_apex' => [
                "{$callsign}, en {$turnName} aguanta un punto más por fuera y retrasa el giro al vértice.",
                'Al no tirarte tan pronto al interior, el coche ya sale encarado para abrir gas a fondo.',
            ],
            'missed_apex' => [
                "{$callsign}, en {$turnName} apunta decidido al piano interior, te estás quedando abierto en el vértice.",
                'Mantén una pizca de freno suave al girar para ceñir el morro al interior.',
            ],
            'pinched_exit' => [
                "{$callsign}, prepara {$turnName} pensando en dejar correr el coche libre a la salida.",
                "Abre manos antes al salir y usa todo el piano exterior de la {$outsideSide}.",
            ],
            'throttle_understeer' => [
                "{$callsign}, en {$turnName} ten paciencia con el gas hasta que el coche termine de rotar.",
                'Espera una décima en el vértice antes de acelerar para no empujar el morro hacia fuera.',
            ],
            'understeer_scrub' => [
                "{$callsign}, en {$turnName} suaviza el giro de volante, estás arrastrando las ruedas delanteras.",
                'Menos ángulo de dirección en el apoyo, deja que el freno regresivo ayude a girar.',
            ],
            'early_braking' => [
                "{$callsign}, en {$turnName} aguanta la referencia y retrasa unos {$metersBrake} metros la frenada.",
                'Confía en la entrada y deja correr el coche limpio hasta el vértice.',
            ],
            'overslow_apex' => [
                "{$callsign}, en {$turnName} descarga el pedal de freno antes al girar hacia el interior.",
                'Menos freno en el vértice, deja que el coche rote con más paso por curva.',
            ],
            'late_throttle' => [
                "{$callsign}, tienes buena línea en {$turnName}, confía para abrir acelerador antes.",
                'Endereza volante desde el vértice y aplica gas decidido sin dudar.',
            ],
            'overdriving_entry' => [
                "{$callsign}, en {$turnName} frena {$metersBrake} metros antes para no colarte del vértice.",
                'Sacrifica un punto la entrada para tocar el piano interior y salir traccionando antes.',
            ],
            default => [
                "{$callsign}, busca más velocidad de paso en {$turnName} suavizando las manos al girar.",
                'Aprovecha todo el piano exterior para no ahogar el motor a la salida.',
            ],
        };
    }
}
