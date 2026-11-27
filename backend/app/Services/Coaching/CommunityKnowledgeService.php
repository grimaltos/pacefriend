<?php

namespace App\Services\Coaching;

use App\Models\CarPhysicsProfile;
use App\Models\CoachingOutcome;
use App\Models\Lap;
use App\Models\ReferenceLap;
use App\Models\Track;
use App\Services\Telemetry\TelemetryNormalizerService;

class CommunityKnowledgeService
{
    public function __construct(
        private readonly TelemetryNormalizerService $normalizer,
    ) {}

    /**
     * Record anonymous effectiveness telemetry for the active coaching instruction
     * so PaceFriend measures which root-cause callouts yield real lap-time gains.
     *
     * @param  array<string, mixed>|null  $previousPlan
     */
    public function recordCoachingOutcome(Lap $lap, ?array $previousPlan): void
    {
        if ($previousPlan === null || empty($previousPlan['focus_turn']) || empty($previousPlan['primary_issue'])) {
            return;
        }

        $focusTurn = (int) $previousPlan['focus_turn'];
        $prevLossMs = (int) ($previousPlan['avg_time_loss_ms'] ?? 0);
        $issueType = substr((string) $previousPlan['primary_issue'], 0, 48);

        foreach (($lap->corner_metrics ?? []) as $cm) {
            if ((int) ($cm['turn'] ?? 0) !== $focusTurn) {
                continue;
            }

            $hadIncident = array_key_exists('is_clean', $cm) ? (! (bool) $cm['is_clean']) : (! $lap->is_clean);
            $newLossMs = isset($cm['time_loss_ms']) ? (int) $cm['time_loss_ms'] : $prevLossMs;

            CoachingOutcome::query()->create([
                'track_id' => $lap->track_id,
                'car_name' => $lap->car_name,
                'turn' => $focusTurn,
                'issue_type' => $issueType,
                'time_delta_ms' => $newLossMs - $prevLossMs, // negative = driver improved!
                'had_incident' => $hadIncident,
            ]);
            break;
        }
    }

    /**
     * Absorb a completed clean lap into PaceFriend's collective intelligence:
     * 1. Auto-discover real corners on uncurated circuits once >= 2 clean laps exist.
     * 2. Update the 100% anonymized PaceFriend Community Reference Pool (strictly monotonic: only faster laps enter).
     * 3. Update the driver's own Personal Auto-Archive per thermal band.
     * 4. Update the learned CarPhysicsProfile (Friction Circle G ceiling) from top-percentile laps only.
     */
    public function ingestCleanLap(Lap $lap, Track $track): void
    {
        if (! $lap->is_clean || ! $lap->is_complete || $lap->lap_time_ms < 10000) {
            return;
        }

        $samples = $lap->samples ?? [];
        if (count($samples) < 20) {
            return;
        }

        // 1. Zero-config automatic corner discovery for new circuits
        if (! $track->corners_is_curated) {
            $this->autoDiscoverTrackCornersIfNeeded($track, $lap, $samples);
        }

        $normalizedPoints = $this->normalizer->normalizeSamples($samples);
        $cornerBaselines = $this->normalizer->extractCornerBaselines(
            $samples,
            $track->corners ?? [],
            $lap->lap_time_ms,
            $track->length_km,
            true
        );
        $sectorSplitsMs = $this->normalizer->extractSectorMetrics(
            $samples,
            $track->effectiveSectors(),
            $lap->lap_time_ms,
            $track->length_km,
            true
        );

        if ($normalizedPoints === [] || $cornerBaselines === []) {
            return;
        }

        // 2. Anonymized PaceFriend Community Reference Pool (driver_name = null, user_id = null)
        $communityBestMs = $this->upsertAnonymizedCommunityReference(
            $lap,
            $track,
            $normalizedPoints,
            $cornerBaselines,
            $sectorSplitsMs
        );

        // 3. Personal Thermal Auto-Archive for this driver
        $this->upsertPersonalThermalReference(
            $lap,
            $track,
            $normalizedPoints,
            $cornerBaselines,
            $sectorSplitsMs
        );

        // 4. Top-Percentile Gate: only feed CarPhysicsProfile if lap is within 103.5% of best known track/car pace
        $anyDryOrWetBestMs = ReferenceLap::query()
            ->where('track_id', $track->id)
            ->where('car_name', $lap->car_name)
            ->where('is_wet', $lap->is_wet)
            ->min('lap_time_ms');
        $benchmarkMs = min(
            $communityBestMs,
            $lap->lap_time_ms,
            $anyDryOrWetBestMs !== null ? (int) $anyDryOrWetBestMs : $lap->lap_time_ms
        );
        if ($lap->lap_time_ms <= (int) round($benchmarkMs * 1.035)) {
            $this->learnCarPhysicsProfile($lap, $cornerBaselines);
        }
    }

    /**
     * Resolve the learned Friction Circle lateral & braking G ceilings for a car & temperature,
     * applying physical thermal tyre degradation (~0.4% per °C away from the measured band)
     * so knowledge learned on ANY circuit/temperature transfers across the entire simulator!
     *
     * @return array{lat_g: float, brake_g: float, combined_g: float}|null
     */
    public function resolveCarGripCeiling(string $carName, bool $isWet, float $trackTempC): ?array
    {
        $band = CarPhysicsProfile::tempBandFor($trackTempC);

        $profiles = CarPhysicsProfile::query()
            ->where('car_name', $carName)
            ->where('is_wet', $isWet)
            ->where('samples_count', '>=', 1)
            ->get();

        if ($profiles->isEmpty()) {
            return null;
        }

        $bestLat = 0.0;
        $bestBrake = 0.0;
        $bestComb = 0.0;

        foreach ($profiles as $p) {
            $thermalFactor = max(0.86, 1.0 - (abs($band - $p->temp_band) * 0.004));
            $bestLat = max($bestLat, round($p->p90_lat_g * $thermalFactor, 2));
            $bestBrake = max($bestBrake, round($p->p90_brake_g * $thermalFactor, 2));
            $bestComb = max($bestComb, round($p->p90_combined_g * $thermalFactor, 2));
        }

        return [
            'lat_g' => $bestLat,
            'brake_g' => $bestBrake,
            'combined_g' => $bestComb,
        ];
    }

    /**
     * Automatically map real braking/apex/exit zones on uncurated tracks from clean telemetry.
     *
     * @param  array<int, array<string, mixed>>  $samples
     */
    private function autoDiscoverTrackCornersIfNeeded(Track $track, Lap $currentLap, array $samples): void
    {
        $cleanCount = Lap::query()
            ->where('track_id', $track->id)
            ->where('is_clean', true)
            ->where('is_complete', true)
            ->count();

        if ($cleanCount < 2) {
            return;
        }

        $grid = $this->normalizer->normalizeSamples($samples, 250);
        $n = count($grid);
        if ($n < 100) {
            return;
        }

        // Smooth speed profile and detect genuine local speed minima where driver braked or loaded lateral G
        $corners = [];
        $minSpacingIdx = 10; // at least 4% of track between distinct corners
        $lastApexIdx = -20;

        for ($i = 0; $i < $n; $i++) {
            $currSpeed = (float) ($grid[$i]['speed'] ?? 0.0);
            if ($currSpeed <= 5.0) {
                continue;
            }

            $isLocalMin = true;
            $maxApproachSpeed = $currSpeed;
            $hadBrakeOrLoad = false;

            for ($offset = -8; $offset <= 8; $offset++) {
                $idx = ($i + $offset + $n) % $n;
                $sp = (float) ($grid[$idx]['speed'] ?? 0.0);
                if ($offset !== 0 && $sp < $currSpeed - 0.15) {
                    $isLocalMin = false;
                    break;
                }
                if ($offset < 0 && $sp > $maxApproachSpeed) {
                    $maxApproachSpeed = $sp;
                }
                if ($offset <= 2 && ((float) ($grid[$idx]['brake'] ?? 0.0) >= 0.12 || abs((float) ($grid[$idx]['lat_g'] ?? 0.0)) >= 0.85 || abs((float) ($grid[$idx]['yaw'] ?? 0.0)) >= 0.15)) {
                    $hadBrakeOrLoad = true;
                }
            }

            if (! $isLocalMin || ! $hadBrakeOrLoad) {
                continue;
            }

            // Require meaningful cornering deceleration (>= 8% speed drop from approach)
            if (($maxApproachSpeed - $currSpeed) < max(3.5, $maxApproachSpeed * 0.08)) {
                continue;
            }

            if (($i - $lastApexIdx) < $minSpacingIdx) {
                continue;
            }

            // Walk backwards up to 14 grid steps (~5.6% of lap) to find braking/deceleration onset
            $brakeIdx = ($i - 5 + $n) % $n;
            for ($back = 1; $back <= 14; $back++) {
                $bIdx = ($i - $back + $n) % $n;
                if ((float) ($grid[$bIdx]['brake'] ?? 0.0) >= 0.10) {
                    $brakeIdx = $bIdx;
                } elseif ($back > 4 && (float) ($grid[$bIdx]['throttle'] ?? 1.0) >= 0.90) {
                    break;
                }
            }

            // Walk forwards up to 12 grid steps to find full-throttle corner exit
            $exitIdx = ($i + 5) % $n;
            for ($fwd = 2; $fwd <= 12; $fwd++) {
                $eIdx = ($i + $fwd) % $n;
                if ((float) ($grid[$eIdx]['throttle'] ?? 0.0) >= 0.92 && (float) ($grid[$eIdx]['brake'] ?? 0.0) < 0.05) {
                    $exitIdx = $eIdx;
                    break;
                }
            }

            $turnNum = count($corners) + 1;
            $corners[] = [
                'turn' => $turnNum,
                'name' => "Curva {$turnNum}",
                'brake_pct' => round((float) $grid[$brakeIdx]['pct'], 4),
                'apex_pct' => round((float) $grid[$i]['pct'], 4),
                'exit_pct' => round((float) $grid[$exitIdx]['pct'], 4),
            ];
            $lastApexIdx = $i;

            if ($turnNum >= 24) {
                break;
            }
        }

        if (count($corners) >= 3) {
            $track->update([
                'corners' => $corners,
                'corners_is_curated' => true,
            ]);

            // Backfill recent laps on this newly mapped track so immediate coaching uses the real corners!
            $recentTrackLaps = Lap::query()
                ->where('track_id', $track->id)
                ->whereNotNull('samples')
                ->orderByDesc('id')
                ->limit(10)
                ->get();

            foreach ($recentTrackLaps as $pastLap) {
                $recomputed = $this->normalizer->extractCornerBaselines(
                    $pastLap->samples ?? [],
                    $corners,
                    $pastLap->lap_time_ms,
                    $track->length_km,
                    (bool) $pastLap->is_clean
                );
                $pastLap->update(['corner_metrics' => $recomputed]);
                if ($pastLap->id === $currentLap->id) {
                    $currentLap->corner_metrics = $recomputed;
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $normalizedPoints
     * @param  array<int, array<string, mixed>>  $cornerBaselines
     * @param  array<int, array<string, mixed>>  $sectorSplitsMs
     */
    private function upsertAnonymizedCommunityReference(
        Lap $lap,
        Track $track,
        array $normalizedPoints,
        array $cornerBaselines,
        array $sectorSplitsMs
    ): int {
        $globalBestInBand = ReferenceLap::query()
            ->whereNull('user_id')
            ->where('track_id', $track->id)
            ->where('car_name', $lap->car_name)
            ->where('is_wet', $lap->is_wet)
            ->whereBetween('track_temp_c', [$lap->track_temp_c - 2.5, $lap->track_temp_c + 2.5])
            ->min('lap_time_ms');

        if ($globalBestInBand !== null && $lap->lap_time_ms >= (int) $globalBestInBand) {
            return (int) $globalBestInBand;
        }

        $existing = ReferenceLap::query()
            ->whereNull('user_id')
            ->where('source', 'pacefriend_community')
            ->where('track_id', $track->id)
            ->where('car_name', $lap->car_name)
            ->where('is_wet', $lap->is_wet)
            ->whereBetween('track_temp_c', [$lap->track_temp_c - 2.5, $lap->track_temp_c + 2.5])
            ->orderBy('lap_time_ms')
            ->first();

        $tempLabel = round($lap->track_temp_c, 1);

        if ($existing === null) {
            ReferenceLap::query()->create([
                'user_id' => null, // Global community pool
                'track_id' => $track->id,
                'car_name' => $lap->car_name,
                'label' => "Pool PaceFriend ({$tempLabel} °C)",
                'lap_time_ms' => $lap->lap_time_ms,
                'track_temp_c' => $lap->track_temp_c,
                'air_temp_c' => $lap->air_temp_c,
                'is_wet' => $lap->is_wet,
                'fuel_level_l' => $lap->fuel_level_l,
                'fuel_used_l' => $lap->fuel_used_l,
                'source' => 'pacefriend_community',
                'driver_name' => null, // Strictly anonymized
                'normalized_points' => $normalizedPoints,
                'corner_baselines' => $cornerBaselines,
                'sector_splits_ms' => $sectorSplitsMs,
            ]);

            return $lap->lap_time_ms;
        }

        if ($lap->lap_time_ms < $existing->lap_time_ms) {
            $existing->update([
                'label' => "Pool PaceFriend ({$tempLabel} °C)",
                'lap_time_ms' => $lap->lap_time_ms,
                'track_temp_c' => $lap->track_temp_c,
                'air_temp_c' => $lap->air_temp_c,
                'fuel_level_l' => $lap->fuel_level_l,
                'fuel_used_l' => $lap->fuel_used_l,
                'driver_name' => null,
                'normalized_points' => $normalizedPoints,
                'corner_baselines' => $cornerBaselines,
                'sector_splits_ms' => $sectorSplitsMs,
            ]);

            return $lap->lap_time_ms;
        }

        return $existing->lap_time_ms;
    }

    /**
     * @param  array<int, array<string, mixed>>  $normalizedPoints
     * @param  array<int, array<string, mixed>>  $cornerBaselines
     * @param  array<int, array<string, mixed>>  $sectorSplitsMs
     */
    private function upsertPersonalThermalReference(
        Lap $lap,
        Track $track,
        array $normalizedPoints,
        array $cornerBaselines,
        array $sectorSplitsMs
    ): void {
        $existing = ReferenceLap::query()
            ->where('user_id', $lap->user_id)
            ->where('source', 'personal_auto')
            ->where('track_id', $track->id)
            ->where('car_name', $lap->car_name)
            ->where('is_wet', $lap->is_wet)
            ->whereBetween('track_temp_c', [$lap->track_temp_c - 3.0, $lap->track_temp_c + 3.0])
            ->orderBy('lap_time_ms')
            ->first();

        $tempLabel = round($lap->track_temp_c, 1);

        if ($existing === null) {
            ReferenceLap::query()->create([
                'user_id' => $lap->user_id,
                'track_id' => $track->id,
                'car_name' => $lap->car_name,
                'label' => "Mejor Propia Auto ({$tempLabel} °C)",
                'lap_time_ms' => $lap->lap_time_ms,
                'track_temp_c' => $lap->track_temp_c,
                'air_temp_c' => $lap->air_temp_c,
                'is_wet' => $lap->is_wet,
                'fuel_level_l' => $lap->fuel_level_l,
                'fuel_used_l' => $lap->fuel_used_l,
                'source' => 'personal_auto',
                'normalized_points' => $normalizedPoints,
                'corner_baselines' => $cornerBaselines,
                'sector_splits_ms' => $sectorSplitsMs,
            ]);
            return;
        }

        if ($lap->lap_time_ms < $existing->lap_time_ms) {
            $existing->update([
                'label' => "Mejor Propia Auto ({$tempLabel} °C)",
                'lap_time_ms' => $lap->lap_time_ms,
                'track_temp_c' => $lap->track_temp_c,
                'air_temp_c' => $lap->air_temp_c,
                'fuel_level_l' => $lap->fuel_level_l,
                'fuel_used_l' => $lap->fuel_used_l,
                'normalized_points' => $normalizedPoints,
                'corner_baselines' => $cornerBaselines,
                'sector_splits_ms' => $sectorSplitsMs,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $cornerBaselines
     */
    private function learnCarPhysicsProfile(Lap $lap, array $cornerBaselines): void
    {
        $latGs = [];
        $brakeGs = [];
        $combGs = [];

        foreach ($cornerBaselines as $cb) {
            if (isset($cb['peak_lat_g']) && (float) $cb['peak_lat_g'] > 0.4) {
                $latGs[] = (float) $cb['peak_lat_g'];
            }
            if (isset($cb['peak_brake_g']) && (float) $cb['peak_brake_g'] > 0.4) {
                $brakeGs[] = (float) $cb['peak_brake_g'];
            }
            if (isset($cb['peak_combined_g']) && (float) $cb['peak_combined_g'] > 0.5) {
                $combGs[] = (float) $cb['peak_combined_g'];
            }
        }

        if ($latGs === [] && $brakeGs === []) {
            return;
        }

        sort($latGs);
        sort($brakeGs);
        sort($combGs);

        $p90 = static fn (array $vals, float $fallback): float => $vals === []
            ? $fallback
            : $vals[min(count($vals) - 1, (int) floor((count($vals) - 1) * 0.85))];

        $lapLatG = $p90($latGs, 1.45);
        $lapBrakeG = $p90($brakeGs, 1.50);
        $lapCombG = $p90($combGs, max($lapLatG, $lapBrakeG));
        $band = CarPhysicsProfile::tempBandFor($lap->track_temp_c);

        $profile = CarPhysicsProfile::query()->firstOrNew([
            'car_name' => $lap->car_name,
            'is_wet' => $lap->is_wet,
            'temp_band' => $band,
        ]);

        if (! $profile->exists || $profile->samples_count === 0) {
            $profile->fill([
                'p90_lat_g' => round($lapLatG, 2),
                'p90_brake_g' => round($lapBrakeG, 2),
                'p90_combined_g' => round($lapCombG, 2),
                'samples_count' => 1,
            ])->save();
            return;
        }

        // Smooth asymmetric EMA favoring genuine grip ceiling discoveries without outlier spikes
        $alpha = $lapLatG > $profile->p90_lat_g ? 0.35 : 0.12;
        $profile->update([
            'p90_lat_g' => round(($profile->p90_lat_g * (1.0 - $alpha)) + ($lapLatG * $alpha), 2),
            'p90_brake_g' => round(($profile->p90_brake_g * (1.0 - $alpha)) + ($lapBrakeG * $alpha), 2),
            'p90_combined_g' => round(($profile->p90_combined_g * (1.0 - $alpha)) + ($lapCombG * $alpha), 2),
            'samples_count' => $profile->samples_count + 1,
        ]);
    }
}
