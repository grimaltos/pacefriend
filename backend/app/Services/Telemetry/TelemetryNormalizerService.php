<?php

namespace App\Services\Telemetry;

use App\Models\ReferenceLap;

class TelemetryNormalizerService
{
    private const FLAG_DIRTY_MASK = 0x07; // OffTrack (1) | OnPitRoad (2) | Incident (4)
    private const FLAG_ABS_ACTIVE = 0x08; // BrakeABSactive (8)
    private const FLAG_CURB_STRIKE = 0x10; // Tire RumblePitch > 0 (16)
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

            $point = [
                'pct' => $targetPct,
                't_ms' => (int) round($this->lerp((float) ($left['t_ms'] ?? 0), (float) ($right['t_ms'] ?? 0), $alpha)),
                'speed' => round($this->lerp((float) ($left['speed'] ?? 0), (float) ($right['speed'] ?? 0), $alpha), 2),
                'throttle' => round($this->lerp((float) ($left['throttle'] ?? 0), (float) ($right['throttle'] ?? 0), $alpha), 3),
                'brake' => round($this->lerp((float) ($left['brake'] ?? 0), (float) ($right['brake'] ?? 0), $alpha), 3),
                'gear' => (int) round($this->lerp((float) ($left['gear'] ?? 1), (float) ($right['gear'] ?? 1), $alpha)),
            ];

            if (isset($left['lat'], $left['lon'], $right['lat'], $right['lon'])) {
                $point['lat'] = round($this->lerp((float) $left['lat'], (float) $right['lat'], $alpha), 7);
                $point['lon'] = round($this->lerp((float) $left['lon'], (float) $right['lon'], $alpha), 7);
            }
            if (isset($left['steer'], $right['steer'])) {
                $point['steer'] = round($this->lerp((float) $left['steer'], (float) $right['steer'], $alpha), 4);
            }
            if (isset($left['yaw'], $right['yaw'])) {
                $point['yaw'] = round($this->lerp((float) $left['yaw'], (float) $right['yaw'], $alpha), 4);
            }
            $sensorBits = (((int) ($left['flags'] ?? 0)) | ((int) ($right['flags'] ?? 0))) & (self::FLAG_ABS_ACTIVE | self::FLAG_CURB_STRIKE);
            if ($sensorBits !== 0) {
                $point['flags'] = $sensorBits;
            }

            $grid[] = $point;
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
                if ((((int) ($s['flags'] ?? 0)) & self::FLAG_DIRTY_MASK) !== 0 && $this->isPctInArc((float) ($s['pct'] ?? 0.0), $guardStart, $windowEnd)) {
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
            $minGear = 99;
            $brakingSamplesCount = 0;
            $absSamplesCount = 0;
            $coastingMs = 0;
            $curbUsed = false;

            foreach ($windowSamples as $idx => $s) {
                $pct = (float) ($s['pct'] ?? 0.0);
                $brake = (float) ($s['brake'] ?? 0.0);
                $throttle = (float) ($s['throttle'] ?? 0.0);
                $speed = (float) ($s['speed'] ?? 0.0);
                $gear = (int) ($s['gear'] ?? 3);
                $flags = (int) ($s['flags'] ?? 0);

                if ($brakeOnsetPct === null && $brake >= 0.12 && ! $passedApex) {
                    $brakeOnsetPct = $pct;
                }
                if ($brake >= 0.25) {
                    $brakingSamplesCount++;
                    if (($flags & self::FLAG_ABS_ACTIVE) !== 0) {
                        $absSamplesCount++;
                    }
                }
                if ($brake > $peakBrake) {
                    $peakBrake = $brake;
                }
                if ($speed < $minSpeedMs) {
                    $minSpeedMs = $speed;
                }

                $distToApex = abs($this->signedPctDiff($pct, $apexPct));
                if ($distToApex <= 0.012) {
                    if ($gear > 0 && $gear < $minGear) {
                        $minGear = $gear;
                    }
                    if (($flags & self::FLAG_CURB_STRIKE) !== 0) {
                        $curbUsed = true;
                    }
                }
                if ($distToApex < $closestApexDist) {
                    $closestApexDist = $distToApex;
                    $trailBrakeAtApex = $brake;
                }

                // Measure dead coasting time (neither brake nor throttle) between braking onset and apex+0.5%
                if ($idx > 0 && $brakeOnsetPct !== null && ! $passedApex && $brake < 0.05 && $throttle < 0.05) {
                    $dt = (int) ($s['t_ms'] ?? 0) - (int) ($windowSamples[$idx - 1]['t_ms'] ?? 0);
                    if ($dt > 0 && $dt < 300) {
                        $coastingMs += $dt;
                    } else {
                        $coastingMs += 50;
                    }
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
            $absRatio = $brakingSamplesCount > 0 ? round($absSamplesCount / $brakingSamplesCount, 3) : 0.0;
            $lineAndSteer = $this->extractCornerGeometryAndSteering(
                $windowSamples,
                $windowStart,
                $brakePct,
                $apexPct,
                $exitPct
            );

            $results[$turn] = array_merge([
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
                'min_gear' => $minGear < 20 ? $minGear : (int) ($exitSample['gear'] ?? 2),
                'abs_active_ratio' => $absRatio,
                'coasting_ms' => $coastingMs,
                'curb_used' => $curbUsed,
                'throttle_pickup_pct' => $throttlePickupPct ?? $apexPct,
                'full_throttle_pct' => $fullThrottlePct ?? $exitPct,
                'exit_speed_ms' => round((float) ($exitSample['speed'] ?? 0.0), 2),
            ], $lineAndSteer);
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
                if ((((int) ($s['flags'] ?? 0)) & self::FLAG_DIRTY_MASK) !== 0 && $this->isPctInArc((float) ($s['pct'] ?? 0.0), $guardStart, $checkEnd)) {
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

            $lineDeltas = $this->compareCornerTrajectoryAndSteering($dc, $rc);
            $dcGear = (int) ($dc['min_gear'] ?? 0);
            $rcGear = (int) ($rc['min_gear'] ?? 0);

            $compared[] = array_merge($dc, [
                'time_loss_ms' => $timeLossMs,
                'early_brake_m' => $earlyBrakeM,
                'late_throttle_m' => $lateThrottleM,
                'apex_speed_delta_kmh' => $apexSpeedDeltaKmh,
                'exit_speed_delta_kmh' => $exitSpeedDeltaKmh,
                'apex_brake_excess' => $apexBrakeExcess,
                'target_gear' => $rcGear > 0 ? $rcGear : null,
                'gear_delta' => ($dcGear > 0 && $rcGear > 0) ? ($dcGear - $rcGear) : 0,
                'abs_excess' => round((float) ($dc['abs_active_ratio'] ?? 0.0) - (float) ($rc['abs_active_ratio'] ?? 0.0), 3),
                'coasting_excess_ms' => (int) ($dc['coasting_ms'] ?? 0) - (int) ($rc['coasting_ms'] ?? 0),
                'missed_curb' => (! empty($rc['curb_used'])) && empty($dc['curb_used']),
                'steer_jerk_excess_degs' => round((float) ($dc['max_steer_rate_degs'] ?? 0.0) - (float) ($rc['max_steer_rate_degs'] ?? 220.0), 1),
            ], $lineDeltas);
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
     * Detect involuntary left-foot brake resting while at full throttle on straights.
     *
     * @param  array<int, array<string, mixed>>  $samples
     */
    public function detectStraightBrakeDragMs(array $samples): int
    {
        $dragMs = 0;
        for ($i = 1, $n = count($samples); $i < $n; $i++) {
            $s = $samples[$i];
            $throttle = (float) ($s['throttle'] ?? 0.0);
            $brake = (float) ($s['brake'] ?? 0.0);
            $speed = (float) ($s['speed'] ?? 0.0);

            if ($throttle >= 0.94 && $brake >= 0.02 && $speed >= 30.0) {
                $dt = (int) ($s['t_ms'] ?? 0) - (int) ($samples[$i - 1]['t_ms'] ?? 0);
                $dragMs += ($dt > 0 && $dt < 300) ? $dt : 50;
            }
        }

        return $dragMs;
    }

    /**
     * Build a complete interactive 2D Trajectory Viewer suite (Full Circuit + Every Corner)
     * comparing driver samples against either an external ReferenceLap or the driver's own Best Session Lap.
     *
     * @param  array<int, array<string, mixed>>  $targetPoints
     * @param  array<int, array<string, mixed>>  $driverSamples
     * @param  array<int, array<string, mixed>>  $corners
     * @return array<string, mixed>|null
     */
    public function buildInteractiveTrajectorySuite(
        array $targetPoints,
        array $driverSamples,
        array $corners,
        string $targetLabel = 'Target'
    ): ?array {
        $refGeo = array_values(array_filter(
            $targetPoints,
            static fn (array $s) => isset($s['lat'], $s['lon']) && (abs((float) $s['lat']) > 0.001 || abs((float) $s['lon']) > 0.001)
        ));
        $drvGeo = array_values(array_filter(
            $driverSamples,
            static fn (array $s) => isset($s['lat'], $s['lon']) && (abs((float) $s['lat']) > 0.001 || abs((float) $s['lon']) > 0.001)
        ));

        if (count($refGeo) < 8 && count($drvGeo) < 8) {
            return null;
        }

        $anchor = $refGeo[0] ?? $drvGeo[0];
        $lat0 = (float) $anchor['lat'];
        $lon0 = (float) $anchor['lon'];
        $cosLat = cos(deg2rad($lat0));

        $projectGlobal = static fn (float $lat, float $lon): array => [
            round(($lon - $lon0) * 111320.0 * $cosLat, 1),
            round(-(($lat - $lat0) * 110540.0), 1),
        ];

        $refCircuit = [];
        $drvCircuit = [];
        $xs = [];
        $ys = [];

        $refStride = max(1, intdiv(count($refGeo), 220));
        for ($i = 0, $n = count($refGeo); $i < $n; $i += $refStride) {
            [$gx, $gy] = $projectGlobal((float) $refGeo[$i]['lat'], (float) $refGeo[$i]['lon']);
            $refCircuit[] = [$gx, $gy];
            $xs[] = $gx;
            $ys[] = $gy;
        }

        $drvStride = max(1, intdiv(count($drvGeo), 220));
        for ($i = 0, $n = count($drvGeo); $i < $n; $i += $drvStride) {
            [$gx, $gy] = $projectGlobal((float) $drvGeo[$i]['lat'], (float) $drvGeo[$i]['lon']);
            $drvCircuit[] = [$gx, $gy];
            $xs[] = $gx;
            $ys[] = $gy;
        }

        $pad = 25.0;
        $minX = floor(min($xs) - $pad);
        $maxX = ceil(max($xs) + $pad);
        $minY = floor(min($ys) - $pad);
        $maxY = ceil(max($ys) + $pad);
        $w = max(50.0, $maxX - $minX);
        $h = max(50.0, $maxY - $minY);

        $cornerMaps = [];
        $effectiveRefForCorners = count($refGeo) >= 8 ? $targetPoints : $driverSamples;
        foreach ($corners as $c) {
            $cm = $this->buildCornerTrajectoryMapFromPoints($effectiveRefForCorners, $driverSamples, $c);
            if ($cm !== null) {
                $cornerMaps[] = $cm;
            }
        }

        return [
            'target_label' => $targetLabel,
            'full_circuit' => [
                'view_box' => "{$minX} {$minY} {$w} {$h}",
                'ref_points' => $refCircuit,
                'driver_points' => $drvCircuit,
            ],
            'corners' => $cornerMaps,
        ];
    }

    /**
     * Build a top-down 2D trajectory comparison (Target vs Driver) for a specific corner,
     * rotated so the approach straight points cleanly upwards (-Y in SVG viewBox coordinates).
     *
     * @param  array<int, array<string, mixed>>  $driverSamples
     * @param  array<string, mixed>  $corner
     * @return array<string, mixed>|null
     */
    public function buildCornerTrajectoryMap(
        ?ReferenceLap $referenceLap,
        array $driverSamples,
        array $corner
    ): ?array {
        if ($referenceLap === null) {
            return null;
        }

        return $this->buildCornerTrajectoryMapFromPoints(
            $referenceLap->normalized_points ?? [],
            $driverSamples,
            $corner
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $refPoints
     * @param  array<int, array<string, mixed>>  $driverSamples
     * @param  array<string, mixed>  $corner
     * @return array<string, mixed>|null
     */
    public function buildCornerTrajectoryMapFromPoints(
        array $refPoints,
        array $driverSamples,
        array $corner
    ): ?array {
        if ($refPoints === [] || $driverSamples === [] || empty($corner)) {
            return null;
        }

        $brakePct = (float) ($corner['brake_pct'] ?? 0.0);
        $apexPct = (float) ($corner['apex_pct'] ?? 0.0);
        $exitPct = (float) ($corner['exit_pct'] ?? 0.0);
        $winStart = $this->wrapPct($brakePct - 0.022);
        $winEnd = $this->wrapPct($exitPct + 0.018);

        $refArc = $this->filterGeoArc($refPoints, $winStart, $winEnd);
        $drvArc = $this->filterGeoArc($driverSamples, $winStart, $winEnd);

        if (count($refArc) < 4 || count($drvArc) < 4) {
            return null;
        }

        $lat0 = (float) $refArc[0]['lat'];
        $lon0 = (float) $refArc[0]['lon'];
        $cosLat = cos(deg2rad($lat0));

        $toMeters = static fn (float $lat, float $lon): array => [
            ($lon - $lon0) * 111320.0 * $cosLat,
            ($lat - $lat0) * 110540.0,
        ];

        // Compute entry heading so we rotate entry direction to point straight up (0, -1 in SVG)
        [$x0, $y0] = $toMeters((float) $refArc[0]['lat'], (float) $refArc[0]['lon']);
        $headIdx = min(3, count($refArc) - 1);
        [$xh, $yh] = $toMeters((float) $refArc[$headIdx]['lat'], (float) $refArc[$headIdx]['lon']);
        $headingAngle = atan2($yh - $y0, $xh - $x0);
        $rot = (M_PI / 2.0) - $headingAngle;
        $cosR = cos($rot);
        $sinR = sin($rot);

        $projectSvg = static function (float $lat, float $lon) use ($toMeters, $cosR, $sinR): array {
            [$mx, $my] = $toMeters($lat, $lon);
            $rx = ($mx * $cosR) - ($my * $sinR);
            $ry = ($mx * $sinR) + ($my * $cosR);
            return [round($rx, 2), round(-$ry, 2)]; // -Y is up in SVG
        };

        $refSvg = [];
        $drvSvg = [];
        $xs = [];
        $ys = [];

        foreach ($refArc as $pt) {
            [$sx, $sy] = $projectSvg((float) $pt['lat'], (float) $pt['lon']);
            $refSvg[] = [$sx, $sy];
            $xs[] = $sx;
            $ys[] = $sy;
        }
        foreach ($drvArc as $pt) {
            [$sx, $sy] = $projectSvg((float) $pt['lat'], (float) $pt['lon']);
            $drvSvg[] = [$sx, $sy];
            $xs[] = $sx;
            $ys[] = $sy;
        }

        $pad = 12.0;
        $minX = floor(min($xs) - $pad);
        $maxX = ceil(max($xs) + $pad);
        $minY = floor(min($ys) - $pad);
        $maxY = ceil(max($ys) + $pad);
        $width = max(24.0, $maxX - $minX);
        $height = max(24.0, $maxY - $minY);

        $findMarker = function (float $targetPct) use ($refArc, $winStart, $projectSvg): array {
            $best = $refArc[0];
            $bestDist = INF;
            $targetArc = $this->arcDistance($winStart, $targetPct);
            foreach ($refArc as $p) {
                $d = abs($this->arcDistance($winStart, (float) $p['pct']) - $targetArc);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $best = $p;
                }
            }
            return $projectSvg((float) $best['lat'], (float) $best['lon']);
        };

        return [
            'turn' => (int) ($corner['turn'] ?? 0),
            'name' => (string) ($corner['name'] ?? ''),
            'view_box' => "{$minX} {$minY} {$width} {$height}",
            'ref_points' => $refSvg,
            'driver_points' => $drvSvg,
            'markers' => [
                'brake' => $findMarker($brakePct),
                'apex' => $findMarker($apexPct),
                'exit' => $findMarker($exitPct),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     * @return array<int, array<string, mixed>>
     */
    private function filterGeoArc(array $samples, float $winStart, float $winEnd): array
    {
        $filtered = array_values(array_filter(
            $samples,
            fn (array $s) => isset($s['lat'], $s['lon'])
                && (abs((float) $s['lat']) > 0.001 || abs((float) $s['lon']) > 0.001)
                && $this->isPctInArc((float) ($s['pct'] ?? 0.0), $winStart, $winEnd)
        ));

        usort($filtered, fn (array $a, array $b) => $this->arcDistance($winStart, (float) $a['pct']) <=> $this->arcDistance($winStart, (float) $b['pct']));

        return $filtered;
    }

    /**
     * Extract spatial trajectory stations (entry, pre-apex, apex, exit) and steering/yaw dynamics.
     *
     * @param  array<int, array<string, mixed>>  $windowSamples
     * @return array<string, mixed>
     */
    private function extractCornerGeometryAndSteering(
        array $windowSamples,
        float $windowStart,
        float $brakePct,
        float $apexPct,
        float $exitPct
    ): array {
        $midEntryArc = ($this->arcDistance($windowStart, $brakePct) + $this->arcDistance($windowStart, $apexPct)) * 0.5;
        $preApexPct = $this->wrapPct($windowStart + $midEntryArc);

        // Steering & yaw averages around the mid-corner / apex zone
        $steerSum = 0.0;
        $steerSignedSum = 0.0;
        $yawSum = 0.0;
        $steerCount = 0;
        $maxSteerRateDegs = 0.0;
        $apexArc = $this->arcDistance($windowStart, $apexPct);
        $brakeArc = $this->arcDistance($windowStart, $brakePct);

        foreach ($windowSamples as $idx => $s) {
            $arc = $this->arcDistance($windowStart, (float) ($s['pct'] ?? 0.0));
            if (abs($arc - $apexArc) <= 0.012 && isset($s['steer'])) {
                $st = (float) $s['steer'];
                $steerSum += abs($st) * 57.29578;
                $steerSignedSum += $st;
                if (isset($s['yaw'])) {
                    $yawSum += abs((float) $s['yaw']) * 57.29578;
                }
                $steerCount++;
            }

            // Turn-in hand smoothness: peak |dSteer/dt| (°/s) between brake_pct and apex_pct
            if ($idx > 0 && $arc >= $brakeArc && $arc <= ($apexArc + 0.005) && isset($s['steer'], $windowSamples[$idx - 1]['steer'])) {
                $dtSec = ((int) ($s['t_ms'] ?? 0) - (int) ($windowSamples[$idx - 1]['t_ms'] ?? 0)) / 1000.0;
                if ($dtSec <= 0.0 || $dtSec > 0.3) {
                    $dtSec = 0.05;
                }
                $dDeg = abs((float) $s['steer'] - (float) $windowSamples[$idx - 1]['steer']) * 57.29578;
                $rate = $dDeg / $dtSec;
                if ($rate < 1500.0 && $rate > $maxSteerRateDegs) {
                    $maxSteerRateDegs = $rate;
                }
            }
        }

        $out = [];
        if ($steerCount > 0) {
            $out['apex_steer_deg'] = round($steerSum / $steerCount, 1);
            $out['apex_yaw_degs'] = round($yawSum / $steerCount, 1);
            $out['max_steer_rate_degs'] = round($maxSteerRateDegs, 1);
            $out['turn_dir'] = $steerSignedSum >= 0.0 ? 1 : -1; // +1 = Left, -1 = Right
        }

        $geoSamples = array_values(array_filter(
            $windowSamples,
            static fn (array $s) => isset($s['lat'], $s['lon']) && (abs((float) $s['lat']) > 0.001 || abs((float) $s['lon']) > 0.001)
        ));

        if (count($geoSamples) < 4) {
            return $out;
        }

        $lat0 = (float) $geoSamples[0]['lat'];
        $cosLat = cos(deg2rad($lat0));

        $sampleStation = function (float $targetPct) use ($geoSamples, $windowStart, $cosLat): array {
            $targetArc = $this->arcDistance($windowStart, $targetPct);
            $n = count($geoSamples);
            $bestIdx = 0;
            $bestDist = INF;

            for ($i = 0; $i < $n; $i++) {
                $d = abs($this->arcDistance($windowStart, (float) $geoSamples[$i]['pct']) - $targetArc);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx = $i;
                }
            }

            $prevIdx = max(0, $bestIdx - 1);
            $nextIdx = min($n - 1, $bestIdx + 1);
            if ($prevIdx === $nextIdx && $n >= 2) {
                $nextIdx = min($n - 1, $prevIdx + 1);
            }

            $dx = ((float) $geoSamples[$nextIdx]['lon'] - (float) $geoSamples[$prevIdx]['lon']) * 111320.0 * $cosLat;
            $dy = ((float) $geoSamples[$nextIdx]['lat'] - (float) $geoSamples[$prevIdx]['lat']) * 110540.0;
            $norm = hypot($dx, $dy);
            $tx = $norm > 0.05 ? ($dx / $norm) : 1.0;
            $ty = $norm > 0.05 ? ($dy / $norm) : 0.0;

            return [
                'lat' => round((float) $geoSamples[$bestIdx]['lat'], 7),
                'lon' => round((float) $geoSamples[$bestIdx]['lon'], 7),
                'tx' => round($tx, 4),
                'ty' => round($ty, 4),
            ];
        };

        $entryGeo = $sampleStation($brakePct);
        $preApexGeo = $sampleStation($preApexPct);
        $apexGeo = $sampleStation($apexPct);
        $exitGeo = $sampleStation($exitPct);

        // Cross product of entry tangent -> exit tangent determines turn direction (+1 Left, -1 Right)
        $cross = ($entryGeo['tx'] * $exitGeo['ty']) - ($entryGeo['ty'] * $exitGeo['tx']);
        if (abs($cross) > 0.08) {
            $out['turn_dir'] = $cross > 0.0 ? 1 : -1;
        }

        $out['geo'] = [
            'entry' => $entryGeo,
            'pre_apex' => $preApexGeo,
            'apex' => $apexGeo,
            'exit' => $exitGeo,
        ];

        return $out;
    }

    /**
     * Compare driver vs reference lateral trajectory offsets (in meters, + = more inside, - = wider)
     * and steering vs yaw understeer scrub.
     *
     * @param  array<string, mixed>  $dc
     * @param  array<string, mixed>  $rc
     * @return array<string, mixed>
     */
    private function compareCornerTrajectoryAndSteering(array $dc, array $rc): array
    {
        $out = [];
        $turnDir = (int) ($rc['turn_dir'] ?? $dc['turn_dir'] ?? -1);
        if ($turnDir === 0) {
            $turnDir = -1;
        }
        $out['turn_dir'] = $turnDir;

        if (isset($dc['apex_steer_deg'], $rc['apex_steer_deg']) && (float) $rc['apex_steer_deg'] > 2.0) {
            $rcYaw = (float) ($rc['apex_yaw_degs'] ?? 0.0);
            $dcYaw = (float) ($dc['apex_yaw_degs'] ?? 0.0);
            $yawRatio = $rcYaw > 2.0 ? min(1.20, $dcYaw / $rcYaw) : 1.0;
            $expectedSteer = (float) $rc['apex_steer_deg'] * $yawRatio;
            $out['understeer_deg'] = round(max(0.0, (float) $dc['apex_steer_deg'] - $expectedSteer), 1);
        }

        if (! isset($dc['geo'], $rc['geo'])) {
            return $out;
        }

        $computeInsideM = static function (array $dPt, array $rPt) use ($turnDir): ?float {
            $cosLat = cos(deg2rad((float) $rPt['lat']));
            $dx = ((float) $dPt['lon'] - (float) $rPt['lon']) * 111320.0 * $cosLat;
            $dy = ((float) $dPt['lat'] - (float) $rPt['lat']) * 110540.0;
            if (hypot($dx, $dy) > 22.0) {
                return null;
            }
            // Left-hand normal of reference heading (tx, ty) is (-ty, tx)
            $offsetLeftM = ($dx * (-(float) $rPt['ty'])) + ($dy * (float) $rPt['tx']);
            return round($offsetLeftM * $turnDir, 2);
        };

        $entryInside = $computeInsideM($dc['geo']['entry'], $rc['geo']['entry']);
        $preApexInside = $computeInsideM($dc['geo']['pre_apex'], $rc['geo']['pre_apex']);
        $apexInside = $computeInsideM($dc['geo']['apex'], $rc['geo']['apex']);
        $exitInside = $computeInsideM($dc['geo']['exit'], $rc['geo']['exit']);

        if ($entryInside !== null && $apexInside !== null && $exitInside !== null) {
            $out['entry_inside_m'] = $entryInside;
            $out['pre_apex_inside_m'] = $preApexInside ?? 0.0;
            $out['apex_inside_m'] = $apexInside;
            $out['exit_inside_m'] = $exitInside;
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $samples
     */
    private function hasAnyFlaggedSample(array $samples): bool
    {
        foreach ($samples as $s) {
            if ((((int) ($s['flags'] ?? 0)) & self::FLAG_DIRTY_MASK) !== 0) {
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
