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
     * (even from laps that had an off-track in a completely different sector, or flying out-lap sectors)
     * against the active weather-matched ReferenceLap and build a personalized CoachingPlan.
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
     * Aggregate per-corner clean passes independently across laps & partials, filtering
     * traffic-slowed outliers per corner and identifying the highest-loss chronic bottleneck.
     *
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

                // Respect per-corner Clean Entry Guard; fall back to lap->is_clean for legacy rows
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
            $apexBrakeExcesses = [];

            foreach ($filtered as $cm) {
                if (! isset($cm['time_loss_ms'])) {
                    continue;
                }
                $losses[] = (int) $cm['time_loss_ms'];
                $earlyBrakes[] = (float) ($cm['early_brake_m'] ?? 0.0);
                $lateThrottles[] = (float) ($cm['late_throttle_m'] ?? 0.0);
                $apexSpeedDeltas[] = (float) ($cm['apex_speed_delta_kmh'] ?? 0.0);
                $apexBrakeExcesses[] = (float) ($cm['apex_brake_excess'] ?? 0.0);
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
                $avgApexBrakeExcess = array_sum($apexBrakeExcesses) / count($apexBrakeExcesses);

                $primaryIssue = $this->classifyPrimaryIssue(
                    $avgEarlyBrakeM,
                    $avgApexBrakeExcess,
                    $avgApexSpeedDelta,
                    $avgLateThrottleM
                );

                $bestCandidate = [
                    'turn' => (int) $turn,
                    'name' => (string) ($sample0['name'] ?? "Curva {$turn}"),
                    'brake_pct' => (float) ($sample0['brake_pct'] ?? 0.0),
                    'exit_pct' => (float) ($sample0['exit_pct'] ?? 0.0),
                    'avg_time_loss_ms' => $avgLoss,
                    'avg_early_brake_m' => round($avgEarlyBrakeM, 1),
                    'avg_late_throttle_m' => round($avgLateThrottleM, 1),
                    'avg_apex_speed_delta_kmh' => round($avgApexSpeedDelta, 1),
                    'avg_apex_brake_excess' => round($avgApexBrakeExcess, 3),
                    'primary_issue' => $primaryIssue,
                ];
            }
        }

        return [$bestCandidate, $maxCleanPasses];
    }

    /**
     * Filter traffic/yellow-flag lifts on a specific corner (> 122% of median segment_time_ms).
     *
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
