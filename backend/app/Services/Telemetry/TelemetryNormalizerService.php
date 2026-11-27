<?php

namespace App\Services\Telemetry;

use App\Models\ReferenceLap;

class TelemetryNormalizerService
{
    /**
     * Resample raw lap telemetry onto a uniform LapDistPct grid [0.000 .. 0.996].
     *
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<int, array<string, float|int>>
     */
    public function normalizeSamples(array $samples, ?int $gridPoints = null): array
    {
        $gridPoints ??= (int) config('pacefriend.normalization_grid_points', 250);
        if (count($samples) < 2 || $gridPoints < 10) {
            return [];
        }

        usort($samples, static fn (array $a, array $b) => ((float) ($a['pct'] ?? 0)) <=> ((float) ($b['pct'] ?? 0)));

        $grid = [];
        $cursor = 0;
        $total = count($samples);

        for ($i = 0; $i < $gridPoints; $i++) {
            $targetPct = round($i / $gridPoints, 4);

            while ($cursor < $total - 2 && (float) ($samples[$cursor + 1]['pct'] ?? 0) < $targetPct) {
                $cursor++;
            }

            $left = $samples[$cursor];
            $right = $samples[min($cursor + 1, $total - 1)];
            $p0 = (float) ($left['pct'] ?? 0.0);
            $p1 = (float) ($right['pct'] ?? $p0);

            $alpha = ($p1 > $p0) ? max(0.0, min(1.0, ($targetPct - $p0) / ($p1 - $p0))) : 0.0;

            $grid[] = [
                'pct' => $targetPct,
                't_ms' => (int) round($this->lerp((float) ($left['t_ms'] ?? 0), (float) ($right['t_ms'] ?? 0), $alpha)),
                'speed' => round($this->lerp((float) ($left['speed'] ?? 0), (float) ($right['speed'] ?? 0), $alpha), 2),
                'throttle' => round($this->lerp((float) ($left['throttle'] ?? 0), (float) ($right['throttle'] ?? 0), $alpha), 3),
                'brake' => round($this->lerp((float) ($left['brake'] ?? 0), (float) ($right['brake'] ?? 0), $alpha), 3),
                'gear' => (int) round($this->lerp((float) ($left['gear'] ?? 1), (float) ($right['gear'] ?? 1), $alpha)),
            ];
        }

        return $grid;
    }

    /**
     * Extract physical corner execution metrics for each segmented turn of the circuit,
     * evaluating per-corner cleanliness with a 250 m Clean Entry Guard.
     *
     * @param  array<int, array<string, mixed>>  $samples
     * @param  array<int, array<string, mixed>>  $corners
     * @return array<int, array<string, mixed>>
     */
    public function extractCornerBaselines(
        array $samples,
        array $corners,
        int $lapTimeMs = 0,
        float $trackLengthKm = 5.0,
        bool $lapMarkedClean = true
    ): array {
        if ($samples === [] || $corners === []) {
            return [];
        }

        $guardPct = min(0.12, 0.250 / max(1.0, $trackLengthKm));
        $anySampleFlagged = $this->hasAnyFlaggedSample($samples);
        $results = [];

        foreach ($corners as $corner) {
            $turn = (int) ($corner['turn'] ?? 0);
            $name = (string) ($corner['name'] ?? "Curva {$turn}");
            $brakePct = (float) ($corner['brake_pct'] ?? 0.0);
            $apexPct = (float) ($corner['apex_pct'] ?? 0.0);
            $exitPct = (float) ($corner['exit_pct'] ?? 0.0);

            $windowStart = $this->wrapPct($brakePct - 0.020);
            $windowEnd = $this->wrapPct($exitPct + 0.015);

            $windowSamples = array_values(array_filter(
                $samples,
                fn (array $s) => $this->isPctInArc((float) ($s['pct'] ?? 0.0), $windowStart, $windowEnd)
            ));

            if (count($windowSamples) < 3) {
                continue;
            }

            // Ensure samples actually span from before brake_pct through exit_pct (crucial for partial sector uploads)
            usort($windowSamples, fn (array $a, array $b) => $this->arcDistance($windowStart, (float) $a['pct']) <=> $this->arcDistance($windowStart, (float) $b['pct']));
            $firstArc = $this->arcDistance($windowStart, (float) ($windowSamples[0]['pct'] ?? $windowStart));
            $lastArc = $this->arcDistance($windowStart, (float) ($windowSamples[count($windowSamples) - 1]['pct'] ?? $windowStart));
            $requiredArc = $this->arcDistance($windowStart, $exitPct);
            if ($firstArc > 0.025 || $lastArc < ($requiredArc * 0.80)) {
                continue;
            }

            // 250 m Clean Entry Guard: check [windowStart - guardPct .. windowEnd] for off-track / pit / incident flags
            $guardStart = $this->wrapPct($windowStart - $guardPct);
            $dirtyInGuard = false;
            foreach ($samples as $s) {
                if (((int) ($s['flags'] ?? 0)) !== 0 && $this->isPctInArc((float) ($s['pct'] ?? 0.0), $guardStart, $windowEnd)) {
                    $dirtyInGuard = true;
                    break;
                }
            }
            $isCleanCorner = ! $dirtyInGuard && ($lapMarkedClean || $anySampleFlagged);

            $segmentTimeMs = $this->estimateSegmentDurationMs($windowSamples, $lapTimeMs, $trackLengthKm);

            $brakeOnsetPct = null;
            $peakBrake = 0.0;
            $minSpeedMs = INF;
            $trailBrakeAtApex = 0.0;
            $closestApexDist = INF;
            $throttlePickupPct = null;
            $fullThrottlePct = null;
            $passedApex = false;

            foreach ($windowSamples as $s) {
                $pct = (float) ($s['pct'] ?? 0.0);
                $brake = (float) ($s['brake'] ?? 0.0);
                $throttle = (float) ($s['throttle'] ?? 0.0);
                $speed = (float) ($s['speed'] ?? 0.0);

                if ($brakeOnsetPct === null && $brake >= 0.12 && ! $passedApex) {
                    $brakeOnsetPct = $pct;
                }
                if ($brake > $peakBrake) {
                    $peakBrake = $brake;
                }
                if ($speed < $minSpeedMs) {
                    $minSpeedMs = $speed;
                }

                $distToApex = abs($this->signedPctDiff($pct, $apexPct));
                if ($distToApex < $closestApexDist) {
                    $closestApexDist = $distToApex;
                    $trailBrakeAtApex = $brake;
                }

                if ($this->arcDistance($windowStart, $pct) >= $this->arcDistance($windowStart, $apexPct)) {
                    $passedApex = true;
                }

                if ($passedApex) {
                    if ($throttlePickupPct === null && $throttle >= 0.25) {
                        $throttlePickupPct = $pct;
                    }
                    if ($fullThrottlePct === null && $throttle >= 0.92) {
                        $fullThrottlePct = $pct;
                    }
                }
            }

            $exitSample = end($windowSamples);

            $results[$turn] = [
                'turn' => $turn,
                'name' => $name,
                'is_clean' => $isCleanCorner,
                'brake_pct' => $brakePct,
                'apex_pct' => $apexPct,
                'exit_pct' => $exitPct,
                'segment_time_ms' => $segmentTimeMs,
                'brake_onset_pct' => $brakeOnsetPct ?? $brakePct,
                'peak_brake' => round($peakBrake, 3),
                'trail_brake_at_apex' => round($trailBrakeAtApex, 3),
                'apex_min_speed_ms' => round(is_finite($minSpeedMs) ? $minSpeedMs : 0.0, 2),
                'throttle_pickup_pct' => $throttlePickupPct ?? $apexPct,
                'full_throttle_pct' => $fullThrottlePct ?? $exitPct,
                'exit_speed_ms' => round((float) ($exitSample['speed'] ?? 0.0), 2),
            ];
        }

        return array_values($results);
    }

    /**
     * Extract per-sector (partial) timings and independent cleanliness with a 250 m Clean Entry Guard.
     *
     * @param  array<int, array<string, mixed>>  $samples
     * @param  array<int, array{sector: int, start_pct: float, end_pct: float}>  $sectors
     * @return array<int, array{sector: int, start_pct: float, end_pct: float, time_ms: int, is_clean: bool}>
     */
    public function extractSectorMetrics(
        array $samples,
        array $sectors,
        int $lapTimeMs = 0,
        float $trackLengthKm = 5.0,
        bool $lapMarkedClean = true
    ): array {
        if ($samples === [] || $sectors === []) {
            return [];
        }

        $guardPct = min(0.12, 0.250 / max(1.0, $trackLengthKm));
        $anySampleFlagged = $this->hasAnyFlaggedSample($samples);
        $metrics = [];

        foreach ($sectors as $sec) {
            $num = (int) ($sec['sector'] ?? 1);
            $startPct = (float) ($sec['start_pct'] ?? 0.0);
            $endPct = (float) ($sec['end_pct'] ?? 1.0);
            $span = max(0.02, $endPct - $startPct);

            $secSamples = array_values(array_filter(
                $samples,
                static fn (array $s) => (float) ($s['pct'] ?? -1.0) >= $startPct && (float) ($s['pct'] ?? -1.0) <= $endPct
            ));

            if (count($secSamples) < 3) {
                continue;
            }

            usort($secSamples, static fn (array $a, array $b) => ((float) $a['pct']) <=> ((float) $b['pct']));
            $coveredSpan = (float) $secSamples[count($secSamples) - 1]['pct'] - (float) $secSamples[0]['pct'];
            if ($coveredSpan < ($span * 0.65)) {
                continue;
            }

            $guardStart = $this->wrapPct($startPct - $guardPct);
            $checkEnd = $endPct >= 0.999 ? 0.9999 : $endPct;
            $dirtyInGuard = false;
            foreach ($samples as $s) {
                if (((int) ($s['flags'] ?? 0)) !== 0 && $this->isPctInArc((float) ($s['pct'] ?? 0.0), $guardStart, $checkEnd)) {
                    $dirtyInGuard = true;
                    break;
                }
            }

            $isClean = ! $dirtyInGuard && ($lapMarkedClean || $anySampleFlagged);
            $rawMs = $this->estimateSegmentDurationMs($secSamples, $lapTimeMs, $trackLengthKm);

            // Extrapolate small sample boundary gaps at sector start/end proportionally
            if ($coveredSpan > 0.01 && $coveredSpan < $span) {
                $rawMs = (int) round($rawMs * ($span / $coveredSpan));
            }

            $metrics[] = [
                'sector' => $num,
                'start_pct' => $startPct,
                'end_pct' => $endPct,
                'time_ms' => max(500, $rawMs),
                'is_clean' => $isClean,
            ];
        }

        // Normalize full-lap sector sum to match authoritative lap_time_ms exactly
        if ($lapTimeMs > 5000 && count($metrics) === count($sectors)) {
            $sum = array_sum(array_column($metrics, 'time_ms'));
            if ($sum > 0) {
                $scale = $lapTimeMs / $sum;
                $running = 0;
                $lastIdx = count($metrics) - 1;
                foreach ($metrics as $idx => &$m) {
                    if ($idx === $lastIdx) {
                        $m['time_ms'] = max(500, $lapTimeMs - $running);
                    } else {
                        $m['time_ms'] = max(500, (int) round($m['time_ms'] * $scale));
                        $running += $m['time_ms'];
                    }
                }
                unset($m);
            }
        }

        return $metrics;
    }

    /**
     * Resolve target sector times from a ReferenceLap (using stored G61 sector splits if count matches,
     * otherwise integrating directly from normalized_points) and attach delta_ms to driver sectors.
     *
     * @param  array<int, array<string, mixed>>  $driverSectors
     * @param  array<int, array{sector: int, start_pct: float, end_pct: float}>  $trackSectors
     * @return array<int, array<string, mixed>>
     */
    public function compareSectorsAgainstReference(
        array $driverSectors,
        ?ReferenceLap $referenceLap,
        array $trackSectors,
        float $trackLengthKm = 5.0
    ): array {
        if ($referenceLap === null || $driverSectors === []) {
            return $driverSectors;
        }

        $refBySector = $this->resolveReferenceSectorMap($referenceLap, $trackSectors, $trackLengthKm);
        if ($refBySector === []) {
            return $driverSectors;
        }

        $out = [];
        foreach ($driverSectors as $ds) {
            $secNum = (int) ($ds['sector'] ?? 0);
            if (isset($refBySector[$secNum]) && $refBySector[$secNum] > 0) {
                $ds['target_time_ms'] = $refBySector[$secNum];
                $ds['delta_ms'] = (int) $ds['time_ms'] - $refBySector[$secNum];
            }
            $out[] = $ds;
        }

        return $out;
    }

    /**
     * @param  array<int, array{sector: int, start_pct: float, end_pct: float}>  $trackSectors
     * @return array<int, int>
     */
    public function resolveReferenceSectorMap(
        ReferenceLap $referenceLap,
        array $trackSectors,
        float $trackLengthKm = 5.0
    ): array {
        $stored = $referenceLap->sector_splits_ms;
        if (is_array($stored) && count($stored) === count($trackSectors)) {
            $map = [];
            foreach ($stored as $idx => $item) {
                if (is_array($item) && isset($item['time_ms'])) {
                    $secNum = (int) ($item['sector'] ?? ($idx + 1));
                    $map[$secNum] = (int) $item['time_ms'];
                } elseif (is_numeric($item)) {
                    $map[$idx + 1] = (int) $item;
                }
            }
            if ($map !== []) {
                return $map;
            }
        }

        $points = $referenceLap->normalized_points ?? [];
        if ($points === []) {
            return [];
        }

        $derived = $this->extractSectorMetrics($points, $trackSectors, $referenceLap->lap_time_ms, $trackLengthKm, true);
        $map = [];
        foreach ($derived as $d) {
            $map[(int) $d['sector']] = (int) $d['time_ms'];
        }

        return $map;
    }

    /**
     * Compare a driver's corner execution metrics against a weather-matched ReferenceLap.
     *
     * @param  array<int, array<string, mixed>>  $driverCorners
     * @param  array<int, array<string, mixed>>  $referenceCorners
     * @return array<int, array<string, mixed>>
     */
    public function compareAgainstReference(array $driverCorners, array $referenceCorners, float $trackLengthKm): array
    {
        $trackMeters = max(1000.0, $trackLengthKm * 1000.0);
        $refByTurn = [];
        foreach ($referenceCorners as $rc) {
            $refByTurn[(int) ($rc['turn'] ?? 0)] = $rc;
        }

        $compared = [];
        foreach ($driverCorners as $dc) {
            $turn = (int) ($dc['turn'] ?? 0);
            $rc = $refByTurn[$turn] ?? null;
            if ($rc === null) {
                $compared[] = $dc;
                continue;
            }

            // Positive early_brake_m means driver braked earlier along track than reference
            $earlyBrakePct = $this->signedPctDiff((float) $dc['brake_onset_pct'], (float) $rc['brake_onset_pct']);
            $earlyBrakeM = round(-$earlyBrakePct * $trackMeters, 1);

            // Positive late_throttle_m means driver reached full throttle later along track than reference
            $lateThrottlePct = $this->signedPctDiff((float) $dc['full_throttle_pct'], (float) $rc['full_throttle_pct']);
            $lateThrottleM = round($lateThrottlePct * $trackMeters, 1);

            $timeLossMs = (int) round((float) $dc['segment_time_ms'] - (float) $rc['segment_time_ms']);
            $apexSpeedDeltaKmh = round(((float) $dc['apex_min_speed_ms'] - (float) $rc['apex_min_speed_ms']) * 3.6, 1);
            $exitSpeedDeltaKmh = round(((float) $dc['exit_speed_ms'] - (float) $rc['exit_speed_ms']) * 3.6, 1);
            $apexBrakeExcess = round((float) $dc['trail_brake_at_apex'] - (float) $rc['trail_brake_at_apex'], 3);

            $compared[] = array_merge($dc, [
                'time_loss_ms' => $timeLossMs,
                'early_brake_m' => $earlyBrakeM,
                'late_throttle_m' => $lateThrottleM,
                'apex_speed_delta_kmh' => $apexSpeedDeltaKmh,
                'exit_speed_delta_kmh' => $exitSpeedDeltaKmh,
                'apex_brake_excess' => $apexBrakeExcess,
            ]);
        }

        return $compared;
    }

    public function wrapPct(float $pct): float
    {
        $wrapped = fmod($pct, 1.0);
        if ($wrapped < 0.0) {
            $wrapped += 1.0;
        }
        return round($wrapped, 4);
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     */
    private function hasAnyFlaggedSample(array $samples): bool
    {
        foreach ($samples as $s) {
            if (((int) ($s['flags'] ?? 0)) !== 0) {
                return true;
            }
        }
        return false;
    }

    private function isPctInArc(float $pct, float $start, float $end): bool
    {
        if ($start <= $end) {
            return $pct >= $start && $pct <= $end;
        }
        return $pct >= $start || $pct <= $end;
    }

    private function arcDistance(float $start, float $pct): float
    {
        $diff = $pct - $start;
        if ($diff < 0.0) {
            $diff += 1.0;
        }
        return $diff;
    }

    /**
     * Shortest signed delta (a - b) on circle [0, 1), in [-0.5, +0.5].
     */
    private function signedPctDiff(float $a, float $b): float
    {
        $d = $a - $b;
        while ($d > 0.5) {
            $d -= 1.0;
        }
        while ($d < -0.5) {
            $d += 1.0;
        }
        return $d;
    }

    /**
     * Compute segment duration cleanly even if start/finish wrap reset t_ms mid-corner.
     *
     * @param  array<int, array<string, mixed>>  $windowSamples
     */
    private function estimateSegmentDurationMs(array $windowSamples, int $lapTimeMs, float $trackLengthKm = 5.0): int
    {
        $trackMeters = max(1000.0, $trackLengthKm * 1000.0);
        $totalMs = 0;

        for ($i = 1, $n = count($windowSamples); $i < $n; $i++) {
            $prevT = (int) ($windowSamples[$i - 1]['t_ms'] ?? 0);
            $currT = (int) ($windowSamples[$i]['t_ms'] ?? 0);
            $dt = $currT - $prevT;

            if ($dt < 0 && $lapTimeMs > 0) {
                $dt = ($lapTimeMs - $prevT) + $currT;
            }
            if ($dt > 0 && $dt < 2000) {
                $totalMs += $dt;
                continue;
            }

            // Physics fallback dt = ds / v_avg when normalized grids lack monotonic t_ms across wrap
            $dsPct = $this->arcDistance((float) ($windowSamples[$i - 1]['pct'] ?? 0), (float) ($windowSamples[$i]['pct'] ?? 0));
            $vAvg = max(8.0, ((float) ($windowSamples[$i - 1]['speed'] ?? 30.0) + (float) ($windowSamples[$i]['speed'] ?? 30.0)) * 0.5);
            $totalMs += (int) round(($dsPct * $trackMeters / $vAvg) * 1000.0);
        }

        return max(50, $totalMs);
    }

    private function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * $t;
    }
}
