<?php

namespace App\Services\Garage61;

use App\Models\Track;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Garage61ClientService
{
    private function client(string $token): PendingRequest
    {
        $baseUrl = rtrim((string) config('pacefriend.garage61.base_url', 'https://garage61.net/api/v1'), '/');

        return Http::baseUrl($baseUrl)
            ->withToken(trim($token))
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * Validate Personal Access Token / OAuth token and return profile + joined teams.
     *
     * @return array<string, mixed>
     */
    public function me(string $token): array
    {
        $resp = $this->client($token)->get('/me');
        if (! $resp->successful()) {
            throw new RuntimeException('Token de Garage 61 inválido o rechazado (HTTP '.$resp->status().').');
        }

        return $resp->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTeams(string $token): array
    {
        $resp = $this->client($token)->get('/teams');
        if (! $resp->successful()) {
            return [];
        }

        $data = $resp->json();
        return $data['items'] ?? (is_array($data) ? $data : []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTracks(string $token): array
    {
        return Cache::remember('garage61:catalog:tracks', now()->addHours(12), function () use ($token) {
            $resp = $this->client($token)->get('/tracks');
            if (! $resp->successful()) {
                return [];
            }
            $data = $resp->json();
            return $data['items'] ?? (is_array($data) ? $data : []);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCars(string $token): array
    {
        return Cache::remember('garage61:catalog:cars', now()->addHours(12), function () use ($token) {
            $resp = $this->client($token)->get('/cars');
            if (! $resp->successful()) {
                return [];
            }
            $data = $resp->json();
            return $data['items'] ?? (is_array($data) ? $data : []);
        });
    }

    /**
     * Resolve Garage 61 numeric/string IDs for a given PaceFriend Track and iRacing Car name.
     *
     * @return array{track_id: int|string|null, car_id: int|string|null}
     */
    public function resolveTrackAndCarIds(string $token, Track $track, string $carName): array
    {
        $g61TrackId = $track->garage61_track_id;

        if ($g61TrackId === null) {
            $needle = strtolower($track->name);
            foreach ($this->listTracks($token) as $gt) {
                $gName = strtolower((string) ($gt['name'] ?? ''));
                $gVariant = strtolower((string) ($gt['variant'] ?? ''));
                $combined = trim("{$gName} {$gVariant}");
                if ($gName !== '' && (str_contains($needle, $gName) || str_contains($combined, $needle))) {
                    $g61TrackId = $gt['id'] ?? null;
                    if (is_numeric($g61TrackId)) {
                        $track->update(['garage61_track_id' => (int) $g61TrackId]);
                    }
                    break;
                }
            }
        }

        $g61CarId = null;
        $carNeedle = strtolower($carName);
        foreach ($this->listCars($token) as $gc) {
            $cName = strtolower((string) ($gc['name'] ?? ''));
            if ($cName !== '' && ($cName === $carNeedle || str_contains($cName, $carNeedle) || str_contains($carNeedle, $cName))) {
                $g61CarId = $gc['id'] ?? null;
                break;
            }
        }

        return [
            'track_id' => $g61TrackId,
            'car_id' => $g61CarId,
        ];
    }

    /**
     * Search Garage 61 laps filtering for accessible telemetry (canViewTelemetry = true)
     * and recent season builds.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    public function searchLaps(string $token, array $query): array
    {
        $maxAgeDays = (int) config('pacefriend.garage61.max_lap_age_days', 90);
        $params = array_filter(array_merge([
            'clean' => 'true',
            'after' => now()->subDays($maxAgeDays)->toIso8601String(),
            'limit' => 25,
        ], $query), static fn ($v) => $v !== null && $v !== '');

        $resp = $this->client($token)->get('/laps', $params);
        if (! $resp->successful()) {
            throw new RuntimeException('Error al consultar vueltas en Garage 61 (HTTP '.$resp->status().').');
        }

        $json = $resp->json() ?? [];
        $items = $json['items'] ?? (is_array($json) ? $json : []);

        // Enforce telemetry visibility & clean status
        return array_values(array_filter($items, static function (array $lap) {
            if (isset($lap['canViewTelemetry']) && $lap['canViewTelemetry'] === false) {
                return false;
            }
            if (isset($lap['clean']) && $lap['clean'] === false) {
                return false;
            }
            return isset($lap['id']);
        }));
    }

    /**
     * Fetch metadata for a single lap ID.
     *
     * @return array<string, mixed>
     */
    public function getLap(string $token, string $lapId): array
    {
        $resp = $this->client($token)->get("/laps/{$lapId}");
        if (! $resp->successful()) {
            throw new RuntimeException("No se pudo obtener la vuelta {$lapId} en Garage 61 (HTTP {$resp->status()}).");
        }

        return $resp->json() ?? [];
    }

    /**
     * Download raw telemetry CSV stream for a specific lap ID.
     */
    public function downloadLapCsv(string $token, string $lapId): string
    {
        $baseUrl = rtrim((string) config('pacefriend.garage61.base_url', 'https://garage61.net/api/v1'), '/');

        $resp = Http::baseUrl($baseUrl)
            ->withToken(trim($token))
            ->withHeaders(['Accept' => 'text/csv, application/octet-stream, */*'])
            ->timeout(25)
            ->get("/laps/{$lapId}/csv");

        if (! $resp->successful() || trim($resp->body()) === '') {
            throw new RuntimeException("No tienes permiso para descargar la telemetría CSV de la vuelta {$lapId} o no está disponible.");
        }

        return $resp->body();
    }

    /**
     * Extract one or multiple Garage 61 lap IDs from a pasted Lap URL, Analysis URL,
     * comma-separated list, or direct ID. If an analysis endpoint exists, resolves its laps.
     *
     * @return array<int, string>
     */
    public function extractLapIds(string $token, string $input): array
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return [];
        }

        // 1. Check query string ?laps=id1,id2 or ?lap=id1 (common in Garage 61 comparison/analysis URLs)
        $queryString = parse_url($trimmed, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $qParams);
            foreach (['laps', 'lap', 'ids'] as $key) {
                if (! empty($qParams[$key])) {
                    $raw = is_array($qParams[$key]) ? $qParams[$key] : explode(',', (string) $qParams[$key]);
                    $cleaned = $this->sanitizeIdList($raw);
                    if ($cleaned !== []) {
                        return $cleaned;
                    }
                }
            }
        }

        // 2. Check /laps/{id} path
        if (preg_match('~/laps/([A-Za-z0-9_-]{8,64})~', $trimmed, $m)) {
            return [$m[1]];
        }

        // 3. Check /analysis/{id} or /analyses/{id} path and attempt API resolution if supported
        if (preg_match('~/analys[ie]s/([A-Za-z0-9_-]{8,64})~', $trimmed, $m)) {
            $analysisId = $m[1];
            $resp = $this->client($token)->get("/analyses/{$analysisId}");
            if ($resp->successful()) {
                $data = $resp->json() ?? [];
                $laps = $data['laps'] ?? $data['lapIds'] ?? [];
                $extracted = [];
                foreach ($laps as $item) {
                    $extracted[] = is_array($item) ? ($item['id'] ?? '') : (string) $item;
                }
                $cleaned = $this->sanitizeIdList($extracted);
                if ($cleaned !== []) {
                    return $cleaned;
                }
            }
            // Fallback: treat the extracted token itself if user pasted a direct lap link variant
            return [$analysisId];
        }

        // 4. Plain comma/space-separated IDs (ULIDs / UUIDs)
        return $this->sanitizeIdList(preg_split('/[\s,]+/', $trimmed) ?: []);
    }

    /**
     * @param  array<int, mixed>  $candidates
     * @return array<int, string>
     */
    private function sanitizeIdList(array $candidates): array
    {
        $valid = [];
        foreach ($candidates as $c) {
            $s = trim((string) $c);
            if (preg_match('/^[A-Za-z0-9_-]{6,64}$/', $s)) {
                $valid[$s] = $s;
            }
        }
        return array_values($valid);
    }
}
