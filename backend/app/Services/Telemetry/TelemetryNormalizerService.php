<?php

namespace App\Services\Telemetry;

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
     * Extract physical corner execution metrics for each segmented turn of the circuit.
     *
     * @param  array<int, array<string, mixed>>  $samples
     * @param  array<int, array<string, mixed>>  $corners
     * @return array<int, array<string, mixed>>
     */
    public function extractCornerBaselines(array $samples, array $corners, int $lapTimeMs = 0): array
    {
        if ($samples === [] || $corners === []) {
            return [];
        }

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

            if (count($windowSamples) < 2) {
                continue;
            }

            // Sort along arc progression relative to windowStart so wrap-around corners (e.g. Spa T1) stay chronological
            usort($windowSamples, fn (array $a, array $b) => $this->arcDistance($windowStart, (float) $a['pct']) <=> $this->arcDistance($windowStart, (float) $b['pct']));

            $segmentTimeMs = $this->estimateSegmentDurationMs($windowSamples, $lapTimeMs);

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
    private function estimateSegmentDurationMs(array $windowSamples, int $lapTimeMs): int
    {
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
            $totalMs += (int) round(($dsPct * 5000.0 / $vAvg) * 1000.0);
        }

        return max(50, $totalMs);
    }

    private function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * $t;
    }
}
