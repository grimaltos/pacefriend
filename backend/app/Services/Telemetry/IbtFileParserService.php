<?php

namespace App\Services\Telemetry;

use InvalidArgumentException;

/**
 * Parses native iRacing binary telemetry files (.ibt) as well as PaceFriend JSON exports
 * to extract track/car metadata, track surface temperatures, and the fastest clean lap.
 */
class IbtFileParserService
{
    private const HEADER_SIZE = 112;
    private const DISK_SUB_HEADER_OFFSET = 112;
    private const VAR_HEADER_SIZE = 144;
    private const TRACK_SURFACE_OFF = 0;

    /**
     * Parse either a binary .ibt buffer or a JSON telemetry payload.
     *
     * @return array{
     *   track_slug: string,
     *   track_name: string,
     *   track_length_km: float,
     *   car_name: string,
     *   lap_time_ms: int,
     *   track_temp_c: float,
     *   air_temp_c: float,
     *   is_wet: bool,
     *   source: string,
     *   samples: array<int, array<string, mixed>>
     * }
     */
    public function parseContent(string $rawBytes, string $originalFilename = ''): array
    {
        $trimmed = ltrim($rawBytes);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return $this->parseJsonContent($trimmed);
        }

        return $this->parseBinaryIbt($rawBytes, $originalFilename);
    }

    /**
     * Decode authentic iRacing .ibt binary layout (irsdk_header + irsdk_diskSubHeader + varHeaders + rows).
     */
    public function parseBinaryIbt(string $bytes, string $filename = ''): array
    {
        $len = strlen($bytes);
        if ($len < self::HEADER_SIZE + 64) {
            throw new InvalidArgumentException('El archivo .ibt es demasiado pequeño o está corrupto.');
        }

        $hdr = unpack(
            'Vver/Vstatus/VtickRate/VsessionInfoUpdate/VsessionInfoLen/VsessionInfoOffset/VnumVars/VvarHeaderOffset/VnumBuf/VbufLen',
            substr($bytes, 0, 40)
        );
        $varBuf0 = unpack('VtickCount/VbufOffset', substr($bytes, 48, 8));
        $diskSub = unpack('PstartDate/estartTime/eendTime/VlapCount/VrecordCount', substr($bytes, self::DISK_SUB_HEADER_OFFSET, 32));

        $tickRate = max(1, (int) $hdr['tickRate']);
        $numVars = (int) $hdr['numVars'];
        $varHdrOffset = (int) $hdr['varHeaderOffset'];
        $bufLen = (int) $hdr['bufLen'];
        $dataOffset = (int) $varBuf0['bufOffset'];
        $recordCount = (int) $diskSub['recordCount'];

        if ($numVars <= 0 || $bufLen <= 0 || $varHdrOffset + ($numVars * self::VAR_HEADER_SIZE) > $len) {
            throw new InvalidArgumentException('Cabecera de variables inválida en archivo .ibt.');
        }

        if ($recordCount <= 0 && $dataOffset > 0 && $dataOffset < $len) {
            $recordCount = intdiv($len - $dataOffset, $bufLen);
        }
        if ($recordCount < 10 || $dataOffset + ($recordCount * $bufLen) > $len) {
            throw new InvalidArgumentException('El archivo .ibt no contiene registros completos de telemetría.');
        }

        $sessionYaml = '';
        $yamlOffset = (int) $hdr['sessionInfoOffset'];
        $yamlLen = (int) $hdr['sessionInfoLen'];
        if ($yamlOffset >= self::HEADER_SIZE && $yamlLen > 0 && $yamlOffset + $yamlLen <= $len) {
            $sessionYaml = rtrim(substr($bytes, $yamlOffset, $yamlLen), "\0");
        }
        $meta = $this->parseSessionYaml($sessionYaml, $filename);

        $vars = $this->indexVarHeaders($bytes, $varHdrOffset, $numVars, $bufLen);
        foreach (['Speed', 'Brake', 'Throttle', 'Lap', 'LapDistPct'] as $req) {
            if (! isset($vars[$req])) {
                throw new InvalidArgumentException("Falta el canal obligatorio {$req} en el archivo .ibt.");
            }
        }

        // Downsample factor so 60 Hz / 360 Hz files store cleanly at ~20 Hz
        $stride = max(1, (int) round($tickRate / 20));

        $laps = [];
        $trackTempSum = 0.0;
        $airTempSum = 0.0;
        $tempSamples = 0;
        $wetDetected = false;

        for ($r = 0; $r < $recordCount; $r++) {
            $rowOffset = $dataOffset + ($r * $bufLen);
            $lapNum = (int) $this->readScalar($bytes, $rowOffset, $vars['Lap']);
            if ($lapNum < 1) {
                continue;
            }

            if (! isset($laps[$lapNum])) {
                $laps[$lapNum] = [
                    'dirty' => false,
                    'min_pct' => 1.0,
                    'max_pct' => 0.0,
                    'start_time' => null,
                    'end_time' => null,
                    'start_fuel' => null,
                    'end_fuel' => null,
                    'samples' => [],
                ];
            }

            $pct = (float) $this->readScalar($bytes, $rowOffset, $vars['LapDistPct']);
            $sessionTime = isset($vars['SessionTime'])
                ? (float) $this->readScalar($bytes, $rowOffset, $vars['SessionTime'])
                : ($r / $tickRate);

            if (isset($vars['FuelLevel'])) {
                $fuelVal = (float) $this->readScalar($bytes, $rowOffset, $vars['FuelLevel']);
                if ($laps[$lapNum]['start_fuel'] === null) {
                    $laps[$lapNum]['start_fuel'] = $fuelVal;
                }
                $laps[$lapNum]['end_fuel'] = $fuelVal;
            }

            if ($laps[$lapNum]['start_time'] === null) {
                $laps[$lapNum]['start_time'] = $sessionTime;
            }
            $laps[$lapNum]['end_time'] = $sessionTime;
            $laps[$lapNum]['min_pct'] = min($laps[$lapNum]['min_pct'], $pct);
            $laps[$lapNum]['max_pct'] = max($laps[$lapNum]['max_pct'], $pct);

            if (isset($vars['PlayerTrackSurface']) && (int) $this->readScalar($bytes, $rowOffset, $vars['PlayerTrackSurface']) === self::TRACK_SURFACE_OFF) {
                $laps[$lapNum]['dirty'] = true;
            }
            if (isset($vars['OnPitRoad']) && (bool) $this->readScalar($bytes, $rowOffset, $vars['OnPitRoad'])) {
                $laps[$lapNum]['dirty'] = true;
            }

            if ($r % ($stride * 10) === 0) {
                $tSlot = $vars['TrackTempCrew'] ?? $vars['TrackTemp'] ?? null;
                if ($tSlot !== null) {
                    $trackTempSum += (float) $this->readScalar($bytes, $rowOffset, $tSlot);
                    $airTempSum += isset($vars['AirTemp']) ? (float) $this->readScalar($bytes, $rowOffset, $vars['AirTemp']) : 20.0;
                    $tempSamples++;
                }
                if (isset($vars['TrackWetness']) && (int) $this->readScalar($bytes, $rowOffset, $vars['TrackWetness']) >= 2) {
                    $wetDetected = true;
                }
            }

            if ($r % $stride !== 0) {
                continue;
            }

            $relMs = (int) round(max(0.0, $sessionTime - $laps[$lapNum]['start_time']) * 1000.0);
            $sampleItem = [
                't_ms' => $relMs,
                'pct' => round($pct, 4),
                'speed' => round((float) $this->readScalar($bytes, $rowOffset, $vars['Speed']), 2),
                'throttle' => round((float) $this->readScalar($bytes, $rowOffset, $vars['Throttle']), 3),
                'brake' => round((float) $this->readScalar($bytes, $rowOffset, $vars['Brake']), 3),
                'lap' => $lapNum,
                'gear' => isset($vars['Gear']) ? (int) $this->readScalar($bytes, $rowOffset, $vars['Gear']) : 3,
                'flags' => 0,
            ];
            if (isset($vars['Lat'], $vars['Lon'])) {
                $latV = (float) $this->readScalar($bytes, $rowOffset, $vars['Lat']);
                $lonV = (float) $this->readScalar($bytes, $rowOffset, $vars['Lon']);
                if (abs($latV) > 0.001 || abs($lonV) > 0.001) {
                    $sampleItem['lat'] = round($latV, 7);
                    $sampleItem['lon'] = round($lonV, 7);
                }
            }
            if (isset($vars['SteeringWheelAngle'])) {
                $sampleItem['steer'] = round((float) $this->readScalar($bytes, $rowOffset, $vars['SteeringWheelAngle']), 4);
            }
            if (isset($vars['YawRate'])) {
                $sampleItem['yaw'] = round((float) $this->readScalar($bytes, $rowOffset, $vars['YawRate']), 4);
            }
            $laps[$lapNum]['samples'][] = $sampleItem;
        }

        // Select fastest complete clean lap (covering at least 0.03 -> 0.97)
        $bestLap = null;
        $bestTimeMs = PHP_INT_MAX;

        foreach ($laps as $lapData) {
            $isComplete = $lapData['min_pct'] <= 0.05 && $lapData['max_pct'] >= 0.95 && count($lapData['samples']) >= 20;
            if (! $isComplete || $lapData['dirty']) {
                continue;
            }
            $durationMs = (int) round(((float) $lapData['end_time'] - (float) $lapData['start_time']) * 1000.0);
            if ($durationMs > 15000 && $durationMs < $bestTimeMs) {
                $bestTimeMs = $durationMs;
                $bestLap = $lapData;
            }
        }

        if ($bestLap === null) {
            throw new InvalidArgumentException('No se encontró ninguna vuelta rápida limpia y completa dentro del archivo .ibt.');
        }

        $fuelLevelL = $bestLap['start_fuel'] !== null ? round((float) $bestLap['start_fuel'], 2) : null;
        $fuelUsedL = ($bestLap['start_fuel'] !== null && $bestLap['end_fuel'] !== null && $bestLap['start_fuel'] > $bestLap['end_fuel'])
            ? round((float) ($bestLap['start_fuel'] - $bestLap['end_fuel']), 3)
            : null;

        return [
            'track_slug' => $meta['track_slug'],
            'track_name' => $meta['track_name'],
            'track_length_km' => $meta['track_length_km'],
            'car_name' => $meta['car_name'],
            'lap_time_ms' => $bestTimeMs,
            'track_temp_c' => $tempSamples > 0 ? round($trackTempSum / $tempSamples, 1) : 26.0,
            'air_temp_c' => $tempSamples > 0 ? round($airTempSum / $tempSamples, 1) : 20.0,
            'is_wet' => $wetDetected,
            'fuel_level_l' => $fuelLevelL,
            'fuel_used_l' => $fuelUsedL,
            'source' => 'ibt_upload',
            'samples' => $bestLap['samples'],
        ];
    }

    private function parseJsonContent(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('El archivo JSON de referencia no tiene un formato válido.');
        }

        $samples = $decoded['samples'] ?? $decoded['normalized_points'] ?? [];
        if (! is_array($samples) || count($samples) < 10) {
            throw new InvalidArgumentException('El JSON debe incluir al menos 10 muestras en "samples".');
        }

        $trackName = (string) ($decoded['track_name'] ?? 'Circuit de Spa-Francorchamps');
        $trackSlug = (string) ($decoded['track_slug'] ?? $this->slugify($trackName));

        $lastSample = end($samples);
        $inferredTimeMs = (int) ($decoded['lap_time_ms'] ?? ($lastSample['t_ms'] ?? 90000));

        return [
            'track_slug' => $trackSlug,
            'track_name' => $trackName,
            'track_length_km' => (float) ($decoded['track_length_km'] ?? 5.0),
            'car_name' => (string) ($decoded['car_name'] ?? 'GT3 Generic'),
            'lap_time_ms' => max(10000, $inferredTimeMs),
            'track_temp_c' => (float) ($decoded['track_temp_c'] ?? 26.0),
            'air_temp_c' => (float) ($decoded['air_temp_c'] ?? 20.0),
            'is_wet' => (bool) ($decoded['is_wet'] ?? false),
            'fuel_level_l' => isset($decoded['fuel_level_l']) ? (float) $decoded['fuel_level_l'] : null,
            'fuel_used_l' => isset($decoded['fuel_used_l']) ? (float) $decoded['fuel_used_l'] : null,
            'source' => 'json_upload',
            'samples' => $samples,
        ];
    }

    /**
     * @return array<string, array{type: int, offset: int}>
     */
    private function indexVarHeaders(string $bytes, int $offset, int $count, int $bufLen): array
    {
        $indexed = [];
        for ($i = 0; $i < $count; $i++) {
            $pos = $offset + ($i * self::VAR_HEADER_SIZE);
            $meta = unpack('Vtype/Voffset/Vcount', substr($bytes, $pos, 12));
            $nameRaw = substr($bytes, $pos + 16, 32);
            $nullPos = strpos($nameRaw, "\0");
            $name = $nullPos !== false ? substr($nameRaw, 0, $nullPos) : trim($nameRaw);

            if ($name !== '' && (int) $meta['offset'] >= 0 && (int) $meta['offset'] < $bufLen) {
                $indexed[$name] = [
                    'type' => (int) $meta['type'],
                    'offset' => (int) $meta['offset'],
                ];
            }
        }
        return $indexed;
    }

    /**
     * @param  array{type: int, offset: int}  $slot
     */
    private function readScalar(string $bytes, int $rowOffset, array $slot): int|float|bool
    {
        $pos = $rowOffset + $slot['offset'];
        return match ($slot['type']) {
            0 => ord($bytes[$pos]),
            1 => ord($bytes[$pos]) !== 0,
            2, 3 => (unpack('lval', substr($bytes, $pos, 4))['val'] ?? 0),
            4 => (float) (unpack('gval', substr($bytes, $pos, 4))['val'] ?? 0.0),
            5 => (float) (unpack('eval', substr($bytes, $pos, 8))['val'] ?? 0.0),
            default => 0,
        };
    }

    /**
     * @return array{track_slug: string, track_name: string, track_length_km: float, car_name: string}
     */
    private function parseSessionYaml(string $yaml, string $filename): array
    {
        $trackSlug = '';
        $trackName = '';
        $trackLengthKm = 5.0;
        $carName = '';
        $driverCarIdx = -1;
        $currentCarIdx = -2;
        $inDrivers = false;

        foreach ( explode("\n", $yaml) as $line ) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if ($line[0] !== ' ' && $line[0] !== "\t" && str_ends_with($trimmed, ':')) {
                $inDrivers = false;
                continue;
            }
            if ($trimmed === 'Drivers:') {
                $inDrivers = true;
                continue;
            }
            if ($inDrivers && str_starts_with($trimmed, '- ')) {
                $currentCarIdx = -2;
                $trimmed = trim(substr($trimmed, 2));
            }
            $colon = strpos($trimmed, ':');
            if ($colon === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $colon));
            $val = trim(trim(substr($trimmed, $colon + 1)), "\"'");

            if (! $inDrivers) {
                switch ($key) {
                    case 'TrackName':
                        $trackSlug = $this->slugify($val);
                        break;
                    case 'TrackDisplayName':
                        $trackName = $val;
                        break;
                    case 'TrackLength':
                        $trackLengthKm = (float) explode(' ', $val)[0];
                        break;
                    case 'DriverCarIdx':
                        $driverCarIdx = (int) $val;
                        break;
                }
            } else {
                if ($key === 'CarIdx') {
                    $currentCarIdx = (int) $val;
                } elseif ($key === 'CarScreenName' && $driverCarIdx >= 0 && $currentCarIdx === $driverCarIdx) {
                    $carName = $val;
                }
            }
        }

        if ($trackName === '') {
            $trackName = $trackSlug !== '' ? ucwords(str_replace('-', ' ', $trackSlug)) : pathinfo($filename, PATHINFO_FILENAME);
        }
        if ($trackSlug === '') {
            $trackSlug = $this->slugify($trackName !== '' ? $trackName : 'unknown-track');
        }
        if ($carName === '') {
            $carName = 'GT3 Car';
        }

        return [
            'track_slug' => $trackSlug,
            'track_name' => $trackName,
            'track_length_km' => $trackLengthKm > 0 ? $trackLengthKm : 5.0,
            'car_name' => $carName,
        ];
    }

    private function slugify(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))) ?? '';
        return trim($slug, '-') ?: 'track';
    }
}
