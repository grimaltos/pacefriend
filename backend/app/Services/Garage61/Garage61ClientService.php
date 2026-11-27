<?php

namespace App\Services\Garage61;

use App\Models\Track;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Official Garage 61 API v1 client adhering strictly to https://garage61.net/api/openapi/v1.yml
 */
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
     * Validate Personal Access Token / OAuth token and return Me schema
     * (id, slug, firstName, lastName, subscriptionPlan, apiPermissions, teams).
     *
     * @return array<string, mixed>
     */
    public function me(string $token): array
    {
        $resp = $this->client($token)->get('/me');
        if (! $resp->successful()) {
            throw new RuntimeException($this->formatApiError($resp, 'Token de Garage 61 inválido o rechazado.'));
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

        return $resp->json('items') ?? [];
    }

    /**
     * Return available iRacing tracks from Garage 61 (TrackInfo: id, name, variant, platform).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTracks(string $token): array
    {
        return Cache::remember('garage61:v2:tracks', now()->addHours(12), function () use ($token) {
            $resp = $this->client($token)->get('/tracks');
            if (! $resp->successful()) {
                return [];
            }
            $items = $resp->json('items') ?? [];
            return array_values(array_filter($items, static fn (array $t) => ($t['platform'] ?? 'iracing') === 'iracing'));
        });
    }

    /**
     * Return available iRacing cars from Garage 61 (CarInfo: id, name, platform).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCars(string $token): array
    {
        return Cache::remember('garage61:v2:cars', now()->addHours(12), function () use ($token) {
            $resp = $this->client($token)->get('/cars');
            if (! $resp->successful()) {
                return [];
            }
            $items = $resp->json('items') ?? [];
            return array_values(array_filter($items, static fn (array $c) => ($c['platform'] ?? 'iracing') === 'iracing'));
        });
    }

    /**
     * List saved analyses accessible to the authenticated user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAnalyses(string $token): array
    {
        $resp = $this->client($token)->get('/analyses', ['limit' => 50]);
        if (! $resp->successful()) {
            return [];
        }

        return $resp->json('items') ?? [];
    }

    /**
     * Resolve Garage 61 integer IDs for a PaceFriend Track and iRacing Car name.
     *
     * @return array{track_id: int|null, car_id: int|null}
     */
    public function resolveTrackAndCarIds(string $token, Track $track, string $carName): array
    {
        $g61TrackId = $track->garage61_track_id;

        if ($g61TrackId === null) {
            $bestScore = 0;
            $targetTokens = $this->tokenize($track->name.' '.str_replace('-', ' ', $track->slug));

            foreach ($this->listTracks($token) as $gt) {
                $candTokens = $this->tokenize(($gt['name'] ?? '').' '.($gt['variant'] ?? ''));
                $overlap = count(array_intersect($targetTokens, $candTokens));
                if ($overlap > $bestScore && $overlap >= 1) {
                    $bestScore = $overlap;
                    $g61TrackId = isset($gt['id']) ? (int) $gt['id'] : null;
                }
            }

            if ($g61TrackId !== null) {
                $track->update(['garage61_track_id' => $g61TrackId]);
            }
        }

        $g61CarId = null;
        $carNeedle = strtolower(trim($carName));
        $bestCarScore = 0;
        $carTokens = $this->tokenize($carName);

        foreach ($this->listCars($token) as $gc) {
            $cName = strtolower(trim((string) ($gc['name'] ?? '')));
            if ($cName === $carNeedle) {
                $g61CarId = (int) $gc['id'];
                break;
            }
            $overlap = count(array_intersect($carTokens, $this->tokenize($cName)));
            if ($overlap > $bestCarScore && $overlap >= 2) {
                $bestCarScore = $overlap;
                $g61CarId = (int) $gc['id'];
            }
        }

        return [
            'track_id' => $g61TrackId,
            'car_id' => $g61CarId,
        ];
    }

    /**
     * Search Garage 61 laps using official OpenAPI v1 parameters:
     * - tracks (required int array, comma-separated)
     * - cars (int array)
     * - teams (team slugs, comma-separated)
     * - minConditionsTrackTemp / maxConditionsTrackTemp
     * - lapTypes=1 (normal full clean laps)
     * - age=-2 (current and previous season)
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    public function searchLaps(string $token, array $query): array
    {
        $params = array_filter(array_merge([
            'lapTypes' => '1',
            'age' => -2,
            'group' => 'none',
            'limit' => 40,
        ], $query), static fn ($v) => $v !== null && $v !== '');

        if (empty($params['tracks'])) {
            throw new RuntimeException('No se pudo mapear el circuito en Garage 61. Selecciónalo en el desplegable de G61.');
        }

        $resp = $this->client($token)->get('/laps', $params);
        if (! $resp->successful()) {
            throw new RuntimeException($this->formatApiError($resp, 'Error al buscar vueltas en Garage 61.'));
        }

        $items = $resp->json('items') ?? [];

        return array_values(array_filter($items, static function (array $lap) {
            if (($lap['canViewTelemetry'] ?? true) === false) {
                return false;
            }
            if (($lap['clean'] ?? true) === false || ($lap['incomplete'] ?? false) === true) {
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
            throw new RuntimeException($this->formatApiError(
                $resp,
                "No se pudo obtener la vuelta {$lapId} en Garage 61"
            ));
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
            ->withHeaders(['Accept' => 'text/csv, application/json, */*'])
            ->timeout(25)
            ->get("/laps/{$lapId}/csv");

        if (! $resp->successful() || trim($resp->body()) === '') {
            throw new RuntimeException($this->formatApiError(
                $resp,
                "No se pudo descargar el CSV de la vuelta {$lapId}"
            ));
        }

        return $resp->body();
    }

    /**
     * Extract one or multiple Garage 61 lap IDs from a pasted Lap URL, Analysis URL/ID,
     * Event URL, or comma-separated list of IDs.
     *
     * @return array<int, string>
     */
    public function extractLapIds(string $token, string $input): array
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return [];
        }

        // 1. Query string ?laps=id1,id2
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

        // 2. Explicit /laps/{id}
        if (preg_match('~/laps/([A-Za-z0-9_-]{8,64})~', $trimmed, $m)) {
            return [$m[1]];
        }

        // 3. Explicit /analysis/{id} or /analyses/{id} -> resolve AnalysisLaps or AnalysisTrainingPlan
        if (preg_match('~/analys[ie]s/([A-Za-z0-9_-]{8,64})~', $trimmed, $m)) {
            return $this->resolveAnalysisLapIds($token, $m[1]);
        }

        // 4. Explicit /events/{id} -> extract fastest clean lap from EventDetails
        if (preg_match('~/events/([A-Za-z0-9_-]{8,64})~', $trimmed, $m)) {
            $resp = $this->client($token)->get("/events/{$m[1]}");
            if ($resp->successful()) {
                $laps = array_filter(
                    $resp->json('laps') ?? [],
                    static fn (array $l) => ($l['clean'] ?? false) && ($l['canViewTelemetry'] ?? false)
                );
                usort($laps, static fn (array $a, array $b) => ($a['lapTime'] ?? 9999) <=> ($b['lapTime'] ?? 9999));
                if ($laps !== []) {
                    return [(string) $laps[0]['id']];
                }
            }
        }

        // 5. Raw ID(s): could be either a Lap ID or an Analysis ID!
        return $this->sanitizeIdList(preg_split('/[\s,]+/', $trimmed) ?: []);
    }

    /**
     * Resolve an Analysis ID into its constituent Lap IDs per OpenAPI schema.
     *
     * @return array<int, string>
     */
    public function resolveAnalysisLapIds(string $token, string $analysisId): array
    {
        $resp = $this->client($token)->get("/analyses/{$analysisId}");
        if (! $resp->successful()) {
            throw new RuntimeException($this->formatApiError(
                $resp,
                "No se pudo abrir el análisis {$analysisId} en Garage 61"
            ));
        }

        $data = $resp->json() ?? [];
        if (($data['type'] ?? '') === 'laps' && ! empty($data['laps'])) {
            return $this->sanitizeIdList($data['laps']);
        }
        if (! empty($data['results']) && is_array($data['results'])) {
            $ids = array_map(static fn (array $r) => $r['lap'] ?? '', $data['results']);
            return $this->sanitizeIdList($ids);
        }

        return [];
    }

    /**
     * Translate Garage 61 HTTP 401/403/429 responses into clear actionable Spanish messages.
     */
    private function formatApiError(Response $resp, string $prefix): string
    {
        $status = $resp->status();
        $apiMsg = (string) ($resp->json('message') ?? '');

        if ($status === 403) {
            return "{$prefix} (HTTP 403 Acceso Denegado). En la API de Garage 61 por defecto solo tienes acceso a tus propias vueltas y a las de tus compañeros de equipo. Si es una vuelta pública de un piloto externo, descárgala como CSV desde la web de Garage 61 y súbela en la pestaña 'Subir Archivo'.";
        }
        if ($status === 429) {
            return "{$prefix} (HTTP 429 Límite de peticiones de Garage 61 alcanzado, espera unos segundos).";
        }

        return $apiMsg !== ''
            ? "{$prefix} (HTTP {$status}: {$apiMsg})"
            : "{$prefix} (HTTP {$status}).";
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $clean = preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)) ?? '';
        $stopWords = ['de', 'la', 'del', 'the', 'at', 'in', 'full', 'gp', 'circuit', 'international', 'raceway', 'speedway', 'autodromo', 'course'];
        $parts = array_filter(explode(' ', $clean), static fn (string $w) => strlen($w) >= 2 && ! in_array($w, $stopWords, true));

        return array_values(array_unique($parts));
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
