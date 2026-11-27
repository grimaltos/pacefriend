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
     * Evaluate the sliding window of the user's last 5 clean laps against the active
     * weather-matched ReferenceLap and build a personalized CoachingPlan.
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
        $minLaps = (int) $radio['min_clean_laps'];

        $recentCleanLaps = Lap::query()
            ->where('user_id', $user->id)
            ->where('track_id', $track->id)
            ->where('car_name', $carName)
            ->where('is_clean', true)
            ->whereNotNull('corner_metrics')
            ->orderByDesc('id')
            ->limit($windowSize * 2)
            ->get();

        // Filter outlier slow laps caused by traffic yields or spins (> 108% of median clean pace)
        $validLaps = $this->filterPaceOutliers($recentCleanLaps->all(), $windowSize);
        $cleanCount = count($validLaps);

        if ($referenceLap === null || $cleanCount < $minLaps) {
            return [
                'clean_laps_count' => $cleanCount,
                'plan' => null,
            ];
        }

        $bottleneck = $this->detectChronicBottleneck($validLaps, $track, $referenceLap, $minLaps);
        if ($bottleneck === null) {
            return [
                'clean_laps_count' => $cleanCount,
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
            'clean_laps_count' => $cleanCount,
            'plan' => [
                'plan_id' => "plan_{$track->slug}_t{$bottleneck['turn']}_{$planSignature}",
                'generated_at' => now()->toIso8601String(),
                'reference_lap_id' => $referenceLap->id,
                'reference_track_temp_c' => $referenceLap->track_temp_c,
                'focus_turn' => $bottleneck['turn'],
                'focus_turn_name' => $bottleneck['name'],
                'primary_issue' => $bottleneck['primary_issue'],
                'avg_time_loss_ms' => $bottleneck['avg_time_loss_ms'],
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
     * @param  array<int, Lap>  $laps
     * @return array<int, Lap>
     */
    private function filterPaceOutliers(array $laps, int $windowSize): array
    {
        if (count($laps) <= 2) {
            return array_slice($laps, 0, $windowSize);
        }

        $times = array_map(static fn (Lap $l) => $l->lap_time_ms, $laps);
        sort($times);
        $median = $times[intdiv(count($times), 2)];
        $maxAllowed = (int) round($median * (float) config('pacefriend.outlier_pace_factor', 1.08));

        $filtered = array_values(array_filter(
            $laps,
            static fn (Lap $l) => $l->lap_time_ms <= $maxAllowed
        ));

        return array_slice($filtered, 0, $windowSize);
    }

    /**
     * Aggregate corner losses across the sliding window and identify the highest-loss chronic corner.
     *
     * @param  array<int, Lap>  $validLaps
     * @return array<string, mixed>|null
     */
    private function detectChronicBottleneck(
        array $validLaps,
        Track $track,
        ReferenceLap $referenceLap,
        int $minChronicCount
    ): ?array {
        $refCorners = $referenceLap->corner_baselines ?? [];
        $aggregates = [];

        foreach ($validLaps as $lap) {
            $metrics = $lap->corner_metrics ?? [];
            // Re-compare against current active reference in case session conditions shifted
            $compared = $this->normalizer->compareAgainstReference($metrics, $refCorners, $track->length_km);

            foreach ($compared as $cm) {
                $turn = (int) ($cm['turn'] ?? 0);
                if ($turn <= 0 || ! isset($cm['time_loss_ms'])) {
                    continue;
                }

                $aggregates[$turn] ??= [
                    'turn' => $turn,
                    'name' => (string) ($cm['name'] ?? "Curva {$turn}"),
                    'brake_pct' => (float) ($cm['brake_pct'] ?? 0.0),
                    'exit_pct' => (float) ($cm['exit_pct'] ?? 0.0),
                    'losses' => [],
                    'early_brakes' => [],
                    'late_throttles' => [],
                    'apex_speed_deltas' => [],
                    'apex_brake_excesses' => [],
                ];

                $aggregates[$turn]['losses'][] = (int) $cm['time_loss_ms'];
                $aggregates[$turn]['early_brakes'][] = (float) ($cm['early_brake_m'] ?? 0.0);
                $aggregates[$turn]['late_throttles'][] = (float) ($cm['late_throttle_m'] ?? 0.0);
                $aggregates[$turn]['apex_speed_deltas'][] = (float) ($cm['apex_speed_delta_kmh'] ?? 0.0);
                $aggregates[$turn]['apex_brake_excesses'][] = (float) ($cm['apex_brake_excess'] ?? 0.0);
            }
        }

        $minLossThreshold = (int) config('pacefriend.min_corner_loss_ms', 80);
        $bestCandidate = null;

        foreach ($aggregates as $agg) {
            $lossLaps = array_filter($agg['losses'], static fn (int $ms) => $ms >= ($minLossThreshold / 2));
            if (count($lossLaps) < $minChronicCount) {
                continue;
            }

            $avgLoss = (int) round(array_sum($agg['losses']) / count($agg['losses']));
            if ($avgLoss < $minLossThreshold) {
                continue;
            }

            if ($bestCandidate === null || $avgLoss > $bestCandidate['avg_time_loss_ms']) {
                $avgEarlyBrakeM = array_sum($agg['early_brakes']) / count($agg['early_brakes']);
                $avgLateThrottleM = array_sum($agg['late_throttles']) / count($agg['late_throttles']);
                $avgApexSpeedDelta = array_sum($agg['apex_speed_deltas']) / count($agg['apex_speed_deltas']);
                $avgApexBrakeExcess = array_sum($agg['apex_brake_excesses']) / count($agg['apex_brake_excesses']);

                $primaryIssue = $this->classifyPrimaryIssue(
                    $avgEarlyBrakeM,
                    $avgApexBrakeExcess,
                    $avgApexSpeedDelta,
                    $avgLateThrottleM
                );

                $bestCandidate = [
                    'turn' => $agg['turn'],
                    'name' => $agg['name'],
                    'brake_pct' => $agg['brake_pct'],
                    'exit_pct' => $agg['exit_pct'],
                    'avg_time_loss_ms' => $avgLoss,
                    'avg_early_brake_m' => round($avgEarlyBrakeM, 1),
                    'avg_late_throttle_m' => round($avgLateThrottleM, 1),
                    'avg_apex_speed_delta_kmh' => round($avgApexSpeedDelta, 1),
                    'avg_apex_brake_excess' => round($avgApexBrakeExcess, 3),
                    'primary_issue' => $primaryIssue,
                ];
            }
        }

        return $bestCandidate;
    }

    private function classifyPrimaryIssue(
        float $earlyBrakeM,
        float $apexBrakeExcess,
        float $apexSpeedDeltaKmh,
        float $lateThrottleM
    ): string {
        if ($earlyBrakeM >= 8.0) {
            return 'early_braking';
        }
        if ($apexBrakeExcess >= 0.08 || $apexSpeedDeltaKmh <= -5.5) {
            return 'overslow_apex';
        }
        if ($lateThrottleM >= 9.0) {
            return 'late_throttle';
        }
        if ($earlyBrakeM <= -8.0 && $lateThrottleM >= 4.0) {
            return 'overdriving_entry';
        }

        return 'minimum_speed';
    }

    /**
     * Translate diagnostic metrics into clear, jargon-free Spanish race engineer instructions.
     * Pre-brake includes the driver's spoken callsign naturally; post-exit stays ultra-concise.
     *
     * @param  array<string, mixed>  $b
     * @return array{0: string, 1: string}
     */
    private function translateHabitToNaturalSpeech(string $callsign, array $b): array
    {
        $turnName = (string) $b['name'];
        $metersBrake = max(5, min(25, (int) (round($b['avg_early_brake_m'] / 5) * 5)));

        return match ($b['primary_issue']) {
            'early_braking' => [
                "{$callsign}, en {$turnName} aguanta la referencia y retrasa unos {$metersBrake} metros la frenada.",
                'Confía en la entrada y deja correr el coche limpio hasta el vértice.',
            ],
            'overslow_apex' => [
                "{$callsign}, en {$turnName} descarga el pedal de freno antes al girar hacia el interior.",
                'Menos freno en el vértice, deja que el coche rote con más paso por curva.',
            ],
            'late_throttle' => [
                "{$callsign}, prepara la rotación pronto en {$turnName} para poder abrir gas antes.",
                'Endereza volante en cuanto pises el vértice y aplica acelerador decidido.',
            ],
            'overdriving_entry' => [
                "{$callsign}, en {$turnName} frena cinco metros antes para no pasarte del vértice.",
                'Sacrifica un punto la entrada para salir traccionando mucho antes a la recta.',
            ],
            default => [
                "{$callsign}, busca más velocidad de paso en {$turnName} suavizando las manos al girar.",
                'Aprovecha todo el piano exterior para no ahogar el motor a la salida.',
            ],
        };
    }
}
