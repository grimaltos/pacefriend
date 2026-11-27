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

        if ($pctIdx === null || $speedIdx === null || $throttleIdx === null || $brakeIdx === null) {
            throw new InvalidArgumentException('El CSV de Garage 61 no incluye las columnas base requeridas (LapDistPct, Speed, Throttle, Brake).');
        }

        $rawRows = [];
        $maxPctSeen = 0.0;
        $maxThrottleSeen = 0.0;
        $maxBrakeSeen = 0.0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if (! isset($cols[$pctIdx], $cols[$speedIdx], $cols[$throttleIdx], $cols[$brakeIdx])) {
                continue;
            }

            $pct = (float) $cols[$pctIdx];
            $speed = (float) $cols[$speedIdx];
            $throttle = (float) $cols[$throttleIdx];
            $brake = (float) $cols[$brakeIdx];
            $gear = ($gearIdx !== null && isset($cols[$gearIdx])) ? (int) $cols[$gearIdx] : 3;
            $timeVal = ($timeIdx !== null && isset($cols[$timeIdx])) ? (float) $cols[$timeIdx] : null;

            $maxPctSeen = max($maxPctSeen, $pct);
            $maxThrottleSeen = max($maxThrottleSeen, $throttle);
            $maxBrakeSeen = max($maxBrakeSeen, $brake);

            $rawRows[] = [
                'pct' => $pct,
                'speed' => $speed,
                'throttle' => $throttle,
                'brake' => $brake,
                'gear' => $gear,
                'time' => $timeVal,
            ];
        }

        if (count($rawRows) < 10) {
            throw new InvalidArgumentException('No se pudieron extraer filas válidas del CSV de Garage 61.');
        }

        // Normalize percentage scales if CSV used 0..100 instead of 0..1
        $pctDivisor = $maxPctSeen > 1.5 ? 100.0 : 1.0;
        $throttleDivisor = $maxThrottleSeen > 1.5 ? 100.0 : 1.0;
        $brakeDivisor = $maxBrakeSeen > 1.5 ? 100.0 : 1.0;

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

            $samples[] = [
                't_ms' => $tMs,
                'pct' => $pct,
                'speed' => $speed,
                'throttle' => round(max(0.0, min(1.0, $r['throttle'] / $throttleDivisor)), 3),
                'brake' => round(max(0.0, min(1.0, $r['brake'] / $brakeDivisor)), 3),
                'lap' => 1,
                'gear' => $r['gear'],
                'flags' => 0,
            ];
        }

        return $samples;
    }
}
