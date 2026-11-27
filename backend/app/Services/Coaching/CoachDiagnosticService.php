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
     * Evaluate recent clean partial/corner passes against either:
     * - External weather-comparable ReferenceLap ('external_target'), OR
     * - Session Self-Optimal Corner Baseline + Intrinsic Vehicle Physics ('session_self')
     *   when no external reference exists or track conditions differ too much.
     *
     * @return array{clean_laps_count: int, plan: array<string, mixed>|null}
     */
    public function buildCoachingPlan(
        User $user,
        Track $track,
        string $carName,
        ?ReferenceLap $referenceLap,
        ?int $drivingSessionId = null
    ): array {
        $radio = $user->radioSettings();
        $windowSize = (int) config('pacefriend.clean_laps_window', 5);
        $minPasses = (int) $radio['min_clean_laps'];

        $query = Lap::query()
            ->where('user_id', $user->id)
            ->where('track_id', $track->id)
            ->where('car_name', $carName)
            ->whereNotNull('corner_metrics');

        // When running in Self-Reference mode (no comparable external target), scope strictly to
        // the active driving session so we never mix corner baselines from incomparable temperatures.
        if ($referenceLap === null && $drivingSessionId !== null) {
            $query->where('driving_session_id', $drivingSessionId);
        }

        $recentLaps = $query
            ->orderByDesc('id')
            ->limit($windowSize * 4)
            ->get()
            ->all();

        $referenceMode = $referenceLap !== null ? 'external_target' : 'session_self';

        [$bottleneck, $maxCleanPasses] = $this->detectChronicBottleneck(
            $recentLaps,
            $track,
            $referenceLap,
            $windowSize,
            $minPasses
        );

        if ($bottleneck === null || $maxCleanPasses < $minPasses) {
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
            $referenceLap?->id ?? "self_{$drivingSessionId}",
            $bottleneck['turn'],
            $bottleneck['primary_issue'],
        ])), 0, 12);

        return [
            'clean_laps_count' => $maxCleanPasses,
            'plan' => [
                'plan_id' => "plan_{$track->slug}_t{$bottleneck['turn']}_{$planSignature}",
                'generated_at' => now()->toIso8601String(),
                'reference_mode' => $referenceMode,
                'reference_lap_id' => $referenceLap?->id,
                'reference_track_temp_c' => $referenceLap?->track_temp_c,
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
        // Collect raw clean passes per turn first so we can synthesize a Self-Best Corner Baseline when $referenceLap === null
        $rawCleanByTurn = [];
        foreach ($recentLaps as $lap) {
            foreach (($lap->corner_metrics ?? []) as $cm) {
                $turn = (int) ($cm['turn'] ?? 0);
                if ($turn <= 0) {
                    continue;
                }
                $isCleanPass = array_key_exists('is_clean', $cm) ? (bool) $cm['is_clean'] : (bool) $lap->is_clean;
                if ($isCleanPass) {
                    $rawCleanByTurn[$turn][] = $cm;
                }
            }
        }

        $refCorners = $referenceLap !== null
            ? ($referenceLap->corner_baselines ?? [])
            : $this->buildSessionSelfBestCorners($rawCleanByTurn);

        $passesByTurn = [];
        foreach ($rawCleanByTurn as $turn => $turnPasses) {
            $passesByTurn[$turn] = $refCorners !== []
                ? $this->normalizer->compareAgainstReference($turnPasses, $refCorners, $track->length_km)
                : $turnPasses;
        }

        $maxCleanPasses = 0;
        $minLossThreshold = $referenceLap !== null
            ? (int) config('pacefriend.min_corner_loss_ms', 80)
            : 60; // Slightly tighter sensitivity when comparing against own session best
        $bestCandidate = null;

        foreach ($passesByTurn as $turn => $rawPasses) {
            $filtered = $this->filterCornerSegmentOutliers($rawPasses, $windowSize);
            $count = count($filtered);
            if ($count > $maxCleanPasses) {
                $maxCleanPasses = $count;
            }

            if ($count < $minChronicCount) {
                continue;
            }

            // In Self-Reference mode, evaluate the most recent passes excluding the single PB pass itself
            // when count > minChronicCount, or fall back to intrinsic physics if all passes are identical.
            $evalSlice = ($referenceLap === null && $count > 2)
                ? array_slice($filtered, 0, $count - 1)
                : $filtered;

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
            $absRatios = [];
            $absExcesses = [];
            $coastingMsList = [];
            $coastingExcesses = [];
            $steerRates = [];
            $steerJerks = [];
            $gearDeltas = [];
            $missedCurbs = 0;
            $targetGear = null;
            $turnDir = -1;

            foreach ($evalSlice as $cm) {
                if (isset($cm['time_loss_ms'])) {
                    $losses[] = (int) $cm['time_loss_ms'];
                }
                $earlyBrakes[] = (float) ($cm['early_brake_m'] ?? 0.0);
                $lateThrottles[] = (float) ($cm['late_throttle_m'] ?? 0.0);
                $apexSpeedDeltas[] = (float) ($cm['apex_speed_delta_kmh'] ?? 0.0);
                $exitSpeedDeltas[] = (float) ($cm['exit_speed_delta_kmh'] ?? 0.0);
                $apexBrakeExcesses[] = (float) ($cm['apex_brake_excess'] ?? 0.0);
                $absRatios[] = (float) ($cm['abs_active_ratio'] ?? 0.0);
                $absExcesses[] = (float) ($cm['abs_excess'] ?? 0.0);
                $coastingMsList[] = (int) ($cm['coasting_ms'] ?? 0);
                $coastingExcesses[] = (int) ($cm['coasting_excess_ms'] ?? 0);
                $steerRates[] = (float) ($cm['max_steer_rate_degs'] ?? 0.0);
                $steerJerks[] = (float) ($cm['steer_jerk_excess_degs'] ?? 0.0);
                $gearDeltas[] = (int) ($cm['gear_delta'] ?? 0);

                if (! empty($cm['missed_curb'])) {
                    $missedCurbs++;
                }
                if (isset($cm['target_gear']) && (int) $cm['target_gear'] > 0) {
                    $targetGear = (int) $cm['target_gear'];
                }
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
                } elseif (isset($cm['apex_steer_deg'], $cm['apex_yaw_degs']) && (float) $cm['apex_steer_deg'] >= 72.0 && (float) $cm['apex_yaw_degs'] <= 20.0) {
                    $understeerDegs[] = (float) $cm['apex_steer_deg'] - 48.0;
                }
            }

            $nEval = max(1, count($evalSlice));
            $avgAbsRatio = array_sum($absRatios) / $nEval;
            $avgCoastingMs = (int) round(array_sum($coastingMsList) / $nEval);
            $avgSteerRate = array_sum($steerRates) / $nEval;
            $avgUndersteerDeg = $understeerDegs !== [] ? (array_sum($understeerDegs) / count($understeerDegs)) : 0.0;

            $requiredLossPasses = $referenceLap !== null ? $minChronicCount : max(1, $minChronicCount - 1);
            $lossPasses = array_filter($losses, static fn (int $ms) => $ms >= ($minLossThreshold / 2));
            $avgLoss = $losses !== [] ? (int) round(array_sum($losses) / count($losses)) : 0;

            // Intrinsic Vehicle Physics check (catches ABS abuse, heavy scrub, coasting, or steering snap even with 0ms self-delta!)
            $intrinsicIssue = null;
            $intrinsicEstimatedLossMs = 0;
            if ($referenceLap === null && ($avgLoss < $minLossThreshold || count($lossPasses) < $requiredLossPasses)) {
                if ($avgAbsRatio >= 0.48) {
                    $intrinsicIssue = 'abs_abuse';
                    $intrinsicEstimatedLossMs = 140;
                } elseif ($avgUndersteerDeg >= 22.0) {
                    $intrinsicIssue = 'understeer_scrub';
                    $intrinsicEstimatedLossMs = 130;
                } elseif ($avgSteerRate >= 390.0) {
                    $intrinsicIssue = 'abrupt_turn_in';
                    $intrinsicEstimatedLossMs = 110;
                } elseif ($avgCoastingMs >= 430) {
                    $intrinsicIssue = 'abrupt_brake_release';
                    $intrinsicEstimatedLossMs = 115;
                }
            }

            $passesComparativeCheck = count($lossPasses) >= $requiredLossPasses && $avgLoss >= $minLossThreshold;
            if (! $passesComparativeCheck && $intrinsicIssue === null) {
                continue;
            }

            $effectiveLossMs = $passesComparativeCheck ? $avgLoss : $intrinsicEstimatedLossMs;

            if ($bestCandidate === null || $effectiveLossMs > $bestCandidate['avg_time_loss_ms']) {
                $sample0 = $filtered[0];
                $avgEarlyBrakeM = array_sum($earlyBrakes) / $nEval;
                $avgLateThrottleM = array_sum($lateThrottles) / $nEval;
                $avgApexSpeedDelta = array_sum($apexSpeedDeltas) / $nEval;
                $avgExitSpeedDelta = array_sum($exitSpeedDeltas) / $nEval;
                $avgApexBrakeExcess = array_sum($apexBrakeExcesses) / $nEval;
                $avgAbsExcess = array_sum($absExcesses) / $nEval;
                $avgCoastingExcess = (int) round(array_sum($coastingExcesses) / $nEval);
                $avgSteerJerk = array_sum($steerJerks) / $nEval;
                $avgGearDelta = (int) round(array_sum($gearDeltas) / $nEval);
                $missedCurbRatio = $missedCurbs / $nEval;

                $hasGeo = count($entryInsides) >= max(1, intdiv($nEval, 2));
                $avgEntryInside = $hasGeo ? (array_sum($entryInsides) / count($entryInsides)) : 0.0;
                $avgPreApexInside = $hasGeo ? (array_sum($preApexInsides) / count($preApexInsides)) : 0.0;
                $avgApexInside = $hasGeo ? (array_sum($apexInsides) / count($apexInsides)) : 0.0;
                $avgExitInside = $hasGeo ? (array_sum($exitInsides) / count($exitInsides)) : 0.0;

                $primaryIssue = $intrinsicIssue ?? $this->classifyCausalRootIssue(
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
                    $avgUndersteerDeg,
                    $avgAbsRatio,
                    $avgAbsExcess,
                    $avgCoastingMs,
                    $avgCoastingExcess,
                    $avgSteerRate,
                    $avgSteerJerk,
                    $avgGearDelta,
                    $missedCurbRatio
                );

                $bestCandidate = [
                    'turn' => (int) $turn,
                    'name' => (string) ($sample0['name'] ?? "Curva {$turn}"),
                    'brake_pct' => (float) ($sample0['brake_pct'] ?? 0.0),
                    'exit_pct' => (float) ($sample0['exit_pct'] ?? 0.0),
                    'turn_dir' => $turnDir,
                    'target_gear' => $targetGear,
                    'gear_delta' => $avgGearDelta,
                    'is_self_ref' => $referenceLap === null,
                    'avg_time_loss_ms' => $effectiveLossMs,
                    'avg_early_brake_m' => round($avgEarlyBrakeM, 1),
                    'avg_late_throttle_m' => round($avgLateThrottleM, 1),
                    'avg_apex_speed_delta_kmh' => round($avgApexSpeedDelta, 1),
                    'avg_apex_brake_excess' => round($avgApexBrakeExcess, 3),
                    'primary_issue' => $primaryIssue,
                    'telemetry_breakdown' => [
                        'has_trajectory' => $hasGeo,
                        'turn_dir' => $turnDir,
                        'target_gear' => $targetGear,
                        'gear_delta' => $avgGearDelta,
                        'abs_active_pct' => (int) round($avgAbsRatio * 100),
                        'coasting_ms' => $avgCoastingMs,
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
     * Synthesize a virtual Reference Corner Baseline by selecting the driver's own fastest
     * clean pass for each individual corner within the current session.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $rawCleanByTurn
     * @return array<int, array<string, mixed>>
     */
    private function buildSessionSelfBestCorners(array $rawCleanByTurn): array
    {
        $selfBaselines = [];
        foreach ($rawCleanByTurn as $turn => $passes) {
            $bestPass = null;
            foreach ($passes as $p) {
                $segMs = (int) ($p['segment_time_ms'] ?? 0);
                if ($segMs <= 50) {
                    continue;
                }
                if ($bestPass === null || $segMs < (int) $bestPass['segment_time_ms']) {
                    $bestPass = $p;
                }
            }
            if ($bestPass !== null) {
                $selfBaselines[$turn] = $bestPass;
            }
        }

        return array_values($selfBaselines);
    }

    /**
     * Chronological causal decision tree incorporating Braking, ABS, Entry Line, Turn-in Smoothness,
     * Coasting/Trail-Brake, Gear Selection, Kerb Usage, Steering Scrub, and Traction.
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
        float $understeerDeg,
        float $absRatio = 0.0,
        float $absExcess = 0.0,
        int $coastingMs = 0,
        int $coastingExcessMs = 0,
        float $steerRateDegs = 0.0,
        float $steerJerkExcess = 0.0,
        int $gearDelta = 0,
        float $missedCurbRatio = 0.0
    ): string {
        // 1. Late braking overshoots apex and compromises exit
        if ($earlyBrakeM <= -7.0 && (($hasGeo && $apexInsideM <= -0.85) || $lateThrottleM >= 4.0 || $exitSpeedDeltaKmh <= -3.5)) {
            return 'overdriving_entry';
        }

        // 2. Heavy ABS saturation lengthening braking & cooking front tyres
        if ($absRatio >= 0.45 && $absExcess >= 0.22) {
            return 'abs_abuse';
        }

        // 3. Narrow entry (failing to open up to outside kerb before turning in)
        if ($hasGeo && $entryInsideM >= 1.15) {
            return 'narrow_entry';
        }

        // 4. Violent steering snap on turn-in breaking front lateral grip
        if ($steerRateDegs >= 370.0 && $steerJerkExcess >= 110.0 && ($understeerDeg >= 12.0 || $apexSpeedDeltaKmh <= -3.0)) {
            return 'abrupt_turn_in';
        }

        // 5. Early apex dive choking exit angle
        if ($hasGeo && $preApexInsideM >= 0.85 && ($lateThrottleM >= 4.5 || $exitSpeedDeltaKmh <= -2.5 || $understeerDeg >= 14.0)) {
            return 'early_apex';
        }

        // 6. Conservative early braking
        if ($earlyBrakeM >= 8.0) {
            return 'early_braking';
        }

        // 7. Abrupt brake release / dead coasting before apex instead of trail-braking
        if ($coastingMs >= 380 && $coastingExcessMs >= 180 && $apexSpeedDeltaKmh <= -2.5) {
            return 'abrupt_brake_release';
        }

        // 8. Wrong minimum gear in apex (1 gear too low causing over-slowing/wheelspin, or 1 gear too high bogging exit)
        if ($gearDelta <= -1 && ($exitSpeedDeltaKmh <= -2.5 || $lateThrottleM >= 4.0)) {
            return 'wrong_gear_low';
        }
        if ($gearDelta >= 1 && ($apexSpeedDeltaKmh <= -3.0 || $exitSpeedDeltaKmh <= -3.0)) {
            return 'wrong_gear_high';
        }

        // 9. Impatient throttle pickup while steering lock is still high -> pushes nose wide
        if ($understeerDeg >= 18.0 && $lateThrottleM <= 3.0 && (! $hasGeo || $apexInsideM > -0.95) && ($exitSpeedDeltaKmh <= -2.0 || ($hasGeo && $exitInsideM <= -0.7))) {
            return 'throttle_understeer';
        }

        // 10. Excessive steering wheel angle scrubbing front tyres mid-corner
        if ($understeerDeg >= 18.0) {
            return 'understeer_scrub';
        }

        // 11. Avoiding usable inside apex kerb that reference rides
        if ($missedCurbRatio >= 0.65 && (! $hasGeo || $apexInsideM <= -0.50)) {
            return 'missed_curb';
        }

        // 12. Missing the inside apex
        if ($hasGeo && $apexInsideM <= -1.05) {
            return 'missed_apex';
        }

        // 13. Holding too much brake pressure at apex
        if ($apexBrakeExcess >= 0.08 || $apexSpeedDeltaKmh <= -5.5) {
            return 'overslow_apex';
        }

        // 14. Pinching the exit
        if ($hasGeo && $exitInsideM >= 1.30) {
            return 'pinched_exit';
        }

        // 15. Clean line & apex, genuine hesitation on throttle
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
     * Telegraphic F1/GT3 race-engineer callouts: concrete, actionable, and ultra-concise (6-10 words).
     *
     * @param  array<string, mixed>  $b
     * @return array{0: string, 1: string}
     */
    private function translateHabitToNaturalSpeech(string $callsign, array $b): array
    {
        $turnName = (string) $b['name'];
        $metersBrake = max(5, min(25, (int) (round(abs((float) $b['avg_early_brake_m']) / 5) * 5)));
        $outsideSide = ((int) ($b['turn_dir'] ?? -1)) > 0 ? 'derecha' : 'izquierda';
        $insideSide = ((int) ($b['turn_dir'] ?? -1)) > 0 ? 'izquierdo' : 'derecho';
        $targetGearWord = $this->gearWord((int) ($b['target_gear'] ?? 0));
        $gearSuffix = ((int) ($b['gear_delta'] ?? 0) !== 0 && $targetGearWord !== '') ? ", en {$targetGearWord}" : '';

        return match ($b['primary_issue']) {
            'abs_abuse' => [
                "{$callsign}, {$turnName}: menos pico de freno, salta mucho ABS{$gearSuffix}.",
                'Roza el umbral sin clavar ABS.',
            ],
            'narrow_entry' => [
                "{$callsign}, {$turnName}: ábrete bien a la {$outsideSide} al entrar{$gearSuffix}.",
                'Usa todo el ancho exterior para girar.',
            ],
            'abrupt_turn_in' => [
                "{$callsign}, {$turnName}: mete volante más progresivo al girar{$gearSuffix}.",
                'Manos suaves para asentar el morro.',
            ],
            'early_apex' => [
                "{$callsign}, {$turnName}: aguanta por fuera y retrasa el giro{$gearSuffix}.",
                'Vértice más tarde para salir recto.',
            ],
            'abrupt_brake_release' => [
                "{$callsign}, {$turnName}: no sueltes freno de golpe al entrar{$gearSuffix}.",
                'Aguanta una caricia de freno hasta el vértice.',
            ],
            'wrong_gear_low' => [
                "{$callsign}, {$turnName}: no bajes tanto, hazla en {$targetGearWord}.",
                'Marcha más larga para traccionar limpio.',
            ],
            'wrong_gear_high' => [
                "{$callsign}, {$turnName}: baja hasta {$targetGearWord} para rotar.",
                'Apóyate en el freno motor al girar.',
            ],
            'missed_curb' => [
                "{$callsign}, {$turnName}: muerde sin miedo el piano {$insideSide}.",
                'Ataca el piano interior para ganar línea.',
            ],
            'missed_apex' => [
                "{$callsign}, {$turnName}: cierra más la línea al piano interior{$gearSuffix}.",
                'Un hilo de freno suave hasta el vértice.',
            ],
            'pinched_exit' => [
                "{$callsign}, {$turnName}: prepara salida libre hacia fuera{$gearSuffix}.",
                "Abre manos antes al piano de la {$outsideSide}.",
            ],
            'throttle_understeer' => [
                "{$callsign}, {$turnName}: espera a rotar antes de dar gas.",
                'No empujes el morro con acelerador.',
            ],
            'understeer_scrub' => [
                "{$callsign}, {$turnName}: menos volante en apoyo, arrastras morro.",
                'Suaviza dirección en mitad de curva.',
            ],
            'early_braking' => [
                "{$callsign}, {$turnName}: retrasa {$metersBrake} metros la frenada{$gearSuffix}.",
                'Deja correr el coche hasta el vértice.',
            ],
            'overslow_apex' => [
                "{$callsign}, {$turnName}: libera freno antes al girar{$gearSuffix}.",
                'Más velocidad mínima en el vértice.',
            ],
            'late_throttle' => [
                "{$callsign}, {$turnName}: buena línea, abre gas antes.",
                'Endereza manos y acelera decidido.',
            ],
            'overdriving_entry' => [
                "{$callsign}, {$turnName}: frena {$metersBrake} metros antes{$gearSuffix}.",
                'Toca el vértice para traccionar pronto.',
            ],
            default => [
                "{$callsign}, {$turnName}: suaviza manos para ganar paso{$gearSuffix}.",
                'Aprovecha todo el piano exterior.',
            ],
        };
    }

    private function gearWord(int $gear): string
    {
        return match ($gear) {
            1 => 'primera',
            2 => 'segunda',
            3 => 'tercera',
            4 => 'cuarta',
            5 => 'quinta',
            6 => 'sexta',
            default => '',
        };
    }
}
