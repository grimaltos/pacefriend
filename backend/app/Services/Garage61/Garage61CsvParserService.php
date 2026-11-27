<?php

namespace App\Services\Garage61;

use InvalidArgumentException;

class Garage61CsvParserService
{
    /**
     * Parse a Garage 61 telemetry CSV stream into PaceFriend standard sample format.
     * Automatically normalizes LapDistPct whether exported as [0..1] or [0..100].
     *
     * @return array<int, array<string, float|int>>
     */
    public function parseCsv(string $csvContent, int $lapTimeMs, float $trackLengthKm = 5.0): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
        if (count($lines) < 10) {
            throw new InvalidArgumentException('El CSV descargado desde Garage 61 contiene muy pocas muestras.');
        }

        $headerCols = str_getcsv((string) array_shift($lines));
        $colMap = [];
        foreach ($headerCols as $index => $colName) {
            $normalized = strtolower(trim((string) $colName, " \t\n\r\0\x0B\"'"));
            $colMap[$normalized] = $index;
        }

        $pctIdx = $colMap['lapdistpct'] ?? $colMap['lap_dist_pct'] ?? $colMap['pct'] ?? null;
        $speedIdx = $colMap['speed'] ?? $colMap['velocity'] ?? null;
        $throttleIdx = $colMap['throttle'] ?? null;
        $brakeIdx = $colMap['brake'] ?? null;
        $gearIdx = $colMap['gear'] ?? null;
        $timeIdx = $colMap['lapcurrentlaptime'] ?? $colMap['sessiontime'] ?? $colMap['time'] ?? null;
        $trackTempIdx = $colMap['tracktempcrew'] ?? $colMap['tracktemp'] ?? $colMap['track_temp'] ?? null;
        $airTempIdx = $colMap['airtemp'] ?? $colMap['air_temp'] ?? null;
        $latIdx = $colMap['lat'] ?? $colMap['latitude'] ?? null;
        $lonIdx = $colMap['lon'] ?? $colMap['lng'] ?? $colMap['longitude'] ?? null;
        $steerIdx = $colMap['steeringwheelangle'] ?? $colMap['steering_wheel_angle'] ?? $colMap['steer'] ?? null;
        $yawIdx = $colMap['yawrate'] ?? $colMap['yaw_rate'] ?? null;
        $absIdx = $colMap['brakeabsactive'] ?? $colMap['absactive'] ?? $colMap['abs'] ?? null;
        $lfRumbleIdx = $colMap['tirelf_rumblepitch'] ?? $colMap['lfrumble'] ?? null;
        $rfRumbleIdx = $colMap['tirerf_rumblepitch'] ?? $colMap['rfrumble'] ?? null;
        $latAccIdx = $colMap['lataccel'] ?? $colMap['lat_accel'] ?? $colMap['latg'] ?? null;
        $longAccIdx = $colMap['longaccel'] ?? $colMap['long_accel'] ?? $colMap['longg'] ?? null;

        if ($pctIdx === null || $speedIdx === null || $throttleIdx === null || $brakeIdx === null) {
            throw new InvalidArgumentException('El CSV de Garage 61 no incluye las columnas base requeridas (LapDistPct, Speed, Throttle, Brake).');
        }

        $rawRows = [];
        $maxPctSeen = 0.0;
        $maxThrottleSeen = 0.0;
        $maxBrakeSeen = 0.0;
        $maxSteerAbs = 0.0;
        $maxYawAbs = 0.0;
        $maxAccAbs = 0.0;
        $detectedTrackTemp = null;
        $detectedAirTemp = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if (! isset($cols[$pctIdx], $cols[$speedIdx], $cols[$throttleIdx], $cols[$brakeIdx])) {
                continue;
            }

            if ($detectedTrackTemp === null && $trackTempIdx !== null && isset($cols[$trackTempIdx]) && is_numeric($cols[$trackTempIdx])) {
                $detectedTrackTemp = round((float) $cols[$trackTempIdx], 1);
            }
            if ($detectedAirTemp === null && $airTempIdx !== null && isset($cols[$airTempIdx]) && is_numeric($cols[$airTempIdx])) {
                $detectedAirTemp = round((float) $cols[$airTempIdx], 1);
            }

            $pct = (float) $cols[$pctIdx];
            $speed = (float) $cols[$speedIdx];
            $throttle = (float) $cols[$throttleIdx];
            $brake = (float) $cols[$brakeIdx];
            $gear = ($gearIdx !== null && isset($cols[$gearIdx])) ? (int) $cols[$gearIdx] : 3;
            $timeVal = ($timeIdx !== null && isset($cols[$timeIdx])) ? (float) $cols[$timeIdx] : null;
            $latVal = ($latIdx !== null && isset($cols[$latIdx]) && is_numeric($cols[$latIdx])) ? (float) $cols[$latIdx] : null;
            $lonVal = ($lonIdx !== null && isset($cols[$lonIdx]) && is_numeric($cols[$lonIdx])) ? (float) $cols[$lonIdx] : null;
            $steerVal = ($steerIdx !== null && isset($cols[$steerIdx]) && is_numeric($cols[$steerIdx])) ? (float) $cols[$steerIdx] : null;
            $yawVal = ($yawIdx !== null && isset($cols[$yawIdx]) && is_numeric($cols[$yawIdx])) ? (float) $cols[$yawIdx] : null;
            $latAccVal = ($latAccIdx !== null && isset($cols[$latAccIdx]) && is_numeric($cols[$latAccIdx])) ? (float) $cols[$latAccIdx] : null;
            $longAccVal = ($longAccIdx !== null && isset($cols[$longAccIdx]) && is_numeric($cols[$longAccIdx])) ? (float) $cols[$longAccIdx] : null;
            if ($latAccVal !== null) {
                $maxAccAbs = max($maxAccAbs, abs($latAccVal));
            }
            if ($longAccVal !== null) {
                $maxAccAbs = max($maxAccAbs, abs($longAccVal));
            }
            $flags = 0;
            if ($absIdx !== null && isset($cols[$absIdx]) && ((float) $cols[$absIdx] > 0.5 || strtolower(trim($cols[$absIdx])) === 'true')) {
                $flags |= 8; // FlagABSActive
            }
            if (($lfRumbleIdx !== null && isset($cols[$lfRumbleIdx]) && (float) $cols[$lfRumbleIdx] > 1.0)
                || ($rfRumbleIdx !== null && isset($cols[$rfRumbleIdx]) && (float) $cols[$rfRumbleIdx] > 1.0)) {
                $flags |= 16; // FlagCurbStrike
            }

            $maxPctSeen = max($maxPctSeen, $pct);
            $maxThrottleSeen = max($maxThrottleSeen, $throttle);
            $maxBrakeSeen = max($maxBrakeSeen, $brake);
            if ($steerVal !== null) {
                $maxSteerAbs = max($maxSteerAbs, abs($steerVal));
            }
            if ($yawVal !== null) {
                $maxYawAbs = max($maxYawAbs, abs($yawVal));
            }

            $rawRows[] = [
                'pct' => $pct,
                'speed' => $speed,
                'throttle' => $throttle,
                'brake' => $brake,
                'gear' => $gear,
                'time' => $timeVal,
                'lat' => $latVal,
                'lon' => $lonVal,
                'steer' => $steerVal,
                'yaw' => $yawVal,
                'lat_acc' => $latAccVal,
                'long_acc' => $longAccVal,
                'flags' => $flags,
            ];
        }

        if (count($rawRows) < 10) {
            throw new InvalidArgumentException('No se pudieron extraer filas válidas del CSV de Garage 61.');
        }

        // Normalize percentage scales if CSV used 0..100 instead of 0..1
        $pctDivisor = $maxPctSeen > 1.5 ? 100.0 : 1.0;
        $throttleDivisor = $maxThrottleSeen > 1.5 ? 100.0 : 1.0;
        $brakeDivisor = $maxBrakeSeen > 1.5 ? 100.0 : 1.0;
        $steerScale = $maxSteerAbs > 14.0 ? (M_PI / 180.0) : 1.0;
        $yawScale = $maxYawAbs > 8.0 ? (M_PI / 180.0) : 1.0;
        $accScale = $maxAccAbs > 5.5 ? (1.0 / 9.80665) : 1.0;

        // Downsample dense 60 Hz CSVs to ~350 points for fast JSONB storage & analysis
        $total = count($rawRows);
        $stride = max(1, intdiv($total, 350));

        $startTime = $rawRows[0]['time'];
        $samples = [];
        $accumulatedMs = 0.0;
        $prevPct = null;
        $trackMeters = max(1000.0, $trackLengthKm * 1000.0);

        for ($i = 0; $i < $total; $i += $stride) {
            $r = $rawRows[$i];
            $pct = round(max(0.0, min(0.9999, $r['pct'] / $pctDivisor)), 4);
            $speed = round($r['speed'], 2);

            if ($r['time'] !== null && $startTime !== null) {
                $tMs = (int) round(max(0.0, ($r['time'] - $startTime) * 1000.0));
            } elseif ($prevPct !== null) {
                $dp = max(0.0, $pct - $prevPct);
                $accumulatedMs += ($dp * $trackMeters / max(8.0, $speed)) * 1000.0;
                $tMs = (int) round($accumulatedMs);
            } else {
                $tMs = (int) round($pct * $lapTimeMs);
            }

            $prevPct = $pct;

            $sampleRow = [
                't_ms' => $tMs,
                'pct' => $pct,
                'speed' => $speed,
                'throttle' => round(max(0.0, min(1.0, $r['throttle'] / $throttleDivisor)), 3),
                'brake' => round(max(0.0, min(1.0, $r['brake'] / $brakeDivisor)), 3),
                'lap' => 1,
                'gear' => $r['gear'],
                'flags' => $r['flags'] ?? 0,
            ];
            if ($r['lat'] !== null && $r['lon'] !== null && (abs($r['lat']) > 0.001 || abs($r['lon']) > 0.001)) {
                $sampleRow['lat'] = round($r['lat'], 7);
                $sampleRow['lon'] = round($r['lon'], 7);
            }
            if ($r['steer'] !== null) {
                $sampleRow['steer'] = round($r['steer'] * $steerScale, 4);
            }
            if ($r['yaw'] !== null) {
                $sampleRow['yaw'] = round($r['yaw'] * $yawScale, 4);
            }
            if ($r['lat_acc'] !== null) {
                $sampleRow['lat_g'] = round($r['lat_acc'] * $accScale, 3);
            }
            if ($r['long_acc'] !== null) {
                $sampleRow['long_g'] = round($r['long_acc'] * $accScale, 3);
            }
            $samples[] = $sampleRow;
        }

        return $samples;
    }

    /**
     * Parse a manually uploaded Garage 61 CSV file and infer lap duration & temperatures if present.
     *
     * @return array{
     *   samples: array<int, array<string, float|int>>,
     *   inferred_lap_time_ms: int,
     *   track_temp_c: float,
     *   air_temp_c: float
     * }
     */
    public function parseUploadedFile(string $csvContent, float $trackLengthKm = 5.0): array
    {
        // Strip UTF-8 BOM if present
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent) ?? $csvContent;
        $samples = $this->parseCsv($clean, 95000, $trackLengthKm);
        $last = end($samples);
        $inferredMs = max(10000, (int) ($last['t_ms'] ?? 95000));

        // Extract optional TrackTemp / AirTemp from first data row if exported
        $lines = preg_split('/\r\n|\r|\n/', trim($clean)) ?: [];
        $trackTemp = 26.0;
        $airTemp = 20.0;
        if (count($lines) >= 2) {
            $headers = array_map(static fn ($h) => strtolower(trim((string) $h, " \"'")), str_getcsv($lines[0]));
            $firstRow = str_getcsv($lines[1]);
            $tIdx = array_search('tracktempcrew', $headers, true);
            if ($tIdx === false) {
                $tIdx = array_search('tracktemp', $headers, true);
            }
            if ($tIdx !== false && isset($firstRow[$tIdx]) && is_numeric($firstRow[$tIdx])) {
                $trackTemp = round((float) $firstRow[$tIdx], 1);
            }
            $aIdx = array_search('airtemp', $headers, true);
            if ($aIdx !== false && isset($firstRow[$aIdx]) && is_numeric($firstRow[$aIdx])) {
                $airTemp = round((float) $firstRow[$aIdx], 1);
            }
        }

        return [
            'samples' => $samples,
            'inferred_lap_time_ms' => $inferredMs,
            'track_temp_c' => $trackTemp,
            'air_temp_c' => $airTemp,
        ];
    }
}
