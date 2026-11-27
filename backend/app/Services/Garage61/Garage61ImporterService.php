<?php

namespace App\Services\Garage61;

use App\Models\DrivingSession;
use App\Models\ReferenceLap;
use App\Models\Track;
use App\Models\User;
use App\Services\Telemetry\TelemetryNormalizerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class Garage61ImporterService
{
    public function __construct(
        private readonly Garage61ClientService $client,
        private readonly Garage61OAuthService $oauth,
        private readonly Garage61CsvParserService $csvParser,
        private readonly TelemetryNormalizerService $normalizer,
    ) {}

    /**
     * Option A (Part 1): Import one or multiple Garage 61 laps from a pasted Lap URL,
     * Analysis URL, or raw ID (automatically trying /analyses/{id} if /laps/{id} fails on raw IDs).
     *
     * @return array<int, ReferenceLap>
     */
    public function importFromUrlOrIds(User $user, string $input): array
    {
        $token = $this->requireUserToken($user);
        $lapIds = $this->client->extractLapIds($token, $input);

        if ($lapIds === []) {
            throw new RuntimeException('No se pudo identificar ningún ID válido en el enlace o texto introducido.');
        }

        // If user pasted a single raw ULID without URL path, check if it's actually an Analysis ID first if getLap fails
        if (count($lapIds) === 1 && ! str_contains($input, '/laps/')) {
            try {
                return [$this->importSingleLap($user, $lapIds[0], 'garage61_manual')];
            } catch (RuntimeException $lapErr) {
                try {
                    $fromAnalysis = $this->client->resolveAnalysisLapIds($token, $lapIds[0]);
                    if ($fromAnalysis !== []) {
                        $lapIds = $fromAnalysis;
                    } else {
                        throw $lapErr;
                    }
                } catch (RuntimeException) {
                    throw $lapErr;
                }
            }
        }

        $imported = [];
        foreach (array_slice($lapIds, 0, 8) as $lapId) {
            $imported[] = $this->importSingleLap($user, $lapId, 'garage61_manual');
        }

        return $imported;
    }

    /**
     * Option A (Part 2): Search Garage 61 laps directly from the PaceFriend panel
     * using official OpenAPI parameters (tracks, cars, minConditionsTrackTemp, teams by slug).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchForPanel(
        User $user,
        ?Track $track,
        string $carName,
        ?float $trackTempC = null,
        bool $onlyTeam = false,
        ?int $explicitG61TrackId = null,
        ?int $explicitG61CarId = null
    ): array {
        $token = $this->requireUserToken($user);

        $g61TrackId = $explicitG61TrackId;
        $g61CarId = $explicitG61CarId;

        if (($g61TrackId === null || $g61CarId === null) && $track !== null) {
            $resolved = $this->client->resolveTrackAndCarIds($token, $track, $carName);
            $g61TrackId ??= $resolved['track_id'];
            $g61CarId ??= $resolved['car_id'];
        }

        $query = [];
        if ($g61TrackId !== null) {
            $query['tracks'] = (string) $g61TrackId;
        }
        if ($g61CarId !== null) {
            $query['cars'] = (string) $g61CarId;
        }
        if ($trackTempC !== null) {
            $query['minConditionsTrackTemp'] = round($trackTempC - 4.0, 1);
            $query['maxConditionsTrackTemp'] = round($trackTempC + 4.0, 1);
        }
        if ($onlyTeam) {
            $teams = $this->client->listTeams($token);
            $teamSlugs = array_filter(array_map(static fn (array $t) => $t['slug'] ?? null, $teams));
            $query['drivers'] = 'me';
            if ($teamSlugs !== []) {
                $query['teams'] = implode(',', $teamSlugs);
            }
        }
        // When $onlyTeam is false, omit `drivers` and `teams` so Garage 61 searches all laps visible
        // to the token (public community if approved by G61, or user + teammates by default).

        $laps = $this->client->searchLaps($token, $query);

        return array_map(
            fn (array $lap) => $this->normalizeLapMeta($lap, $track?->name ?? '', $carName),
            $laps
        );
    }

    /**
     * Download, normalize, and store a single Garage 61 lap (idempotent via external_id).
     */
    public function importSingleLap(
        User $user,
        string $lapId,
        string $source = 'garage61_manual',
        ?Track $knownTrack = null,
        ?string $knownCarName = null
    ): ReferenceLap {
        $existing = ReferenceLap::query()
            ->where('user_id', $user->id)
            ->where('external_id', $lapId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $token = $this->requireUserToken($user);
        $meta = $this->client->getLap($token, $lapId);
        $normalizedMeta = $this->normalizeLapMeta(
            $meta,
            $knownTrack?->name ?? 'Circuit de Spa-Francorchamps',
            $knownCarName ?? 'GT3 Car'
        );

        $track = $knownTrack ?? Track::resolveOrCreateCurated(
            $normalizedMeta['track_slug'],
            $normalizedMeta['track_name'],
            5.0
        );

        $csv = $this->client->downloadLapCsv($token, $lapId);
        $samples = $this->csvParser->parseCsv($csv, $normalizedMeta['lap_time_ms'], $track->length_km);

        $normalizedPoints = $this->normalizer->normalizeSamples($samples);
        $cornerBaselines = $this->normalizer->extractCornerBaselines(
            $samples,
            $track->corners ?? [],
            $normalizedMeta['lap_time_ms']
        );

        $driverLabel = $normalizedMeta['driver_name'] !== ''
            ? "G61 · {$normalizedMeta['driver_name']} ({$normalizedMeta['track_temp_c']} °C)"
            : "G61 #{$lapId} ({$normalizedMeta['track_temp_c']} °C)";

        return ReferenceLap::query()->create([
            'user_id' => $user->id,
            'track_id' => $track->id,
            'car_name' => $knownCarName ?: $normalizedMeta['car_name'],
            'label' => $driverLabel,
            'lap_time_ms' => $normalizedMeta['lap_time_ms'],
            'track_temp_c' => $normalizedMeta['track_temp_c'],
            'air_temp_c' => $normalizedMeta['air_temp_c'],
            'is_wet' => $normalizedMeta['is_wet'],
            'fuel_level_l' => $normalizedMeta['fuel_level_l'],
            'fuel_used_l' => $normalizedMeta['fuel_used_l'],
            'source' => $source,
            'external_id' => $lapId,
            'driver_name' => $normalizedMeta['driver_name'] ?: null,
            'normalized_points' => $normalizedPoints,
            'corner_baselines' => $cornerBaselines,
        ]);
    }

    /**
     * Option C: Progressive Autopilot in real time.
     */
    public function syncAutopilotForSession(DrivingSession $session, int $driverCleanPaceMs): ?ReferenceLap
    {
        $session->loadMissing(['user', 'track', 'activeReferenceLap']);
        $user = $session->user;

        if (! $user->autopilot_enabled || blank($user->garage61_token)) {
            return null;
        }

        $tempBand = (float) config('pacefriend.garage61.autopilot_temp_band_c', 2.5);
        $levelUpThresholdMs = (int) config('pacefriend.garage61.level_up_threshold_ms', 250);
        $currentRef = $session->activeReferenceLap;

        // Never override a user-pinned master reference lap with matching wet/dry surface
        if ($currentRef !== null && $currentRef->is_pinned && $currentRef->is_wet === $session->is_wet) {
            return $currentRef;
        }

        $hasThermalMatch = $currentRef !== null
            && $currentRef->is_wet === $session->is_wet
            && abs($currentRef->track_temp_c - $session->avg_track_temp_c) <= $tempBand;

        $driverBeatCurrentRef = $currentRef !== null
            && $user->autopilot_mode === 'progressive'
            && $driverCleanPaceMs > 0
            && $driverCleanPaceMs <= ($currentRef->lap_time_ms + $levelUpThresholdMs);

        if ($hasThermalMatch && ! $driverBeatCurrentRef) {
            return $currentRef;
        }

        try {
            $token = $this->oauth->resolveValidAccessToken($user);
            $ids = $this->client->resolveTrackAndCarIds($token, $session->track, $session->car_name);
            if ($ids['track_id'] === null) {
                return null;
            }

            $baseQuery = [
                'tracks' => (string) $ids['track_id'],
                'minConditionsTrackTemp' => round($session->avg_track_temp_c - $tempBand, 1),
                'maxConditionsTrackTemp' => round($session->avg_track_temp_c + $tempBand, 1),
            ];
            if ($ids['car_id'] !== null) {
                $baseQuery['cars'] = (string) $ids['car_id'];
            }

            $scope = $user->reference_scope ?: 'team_then_public';
            $candidates = $this->queryCandidatesByScope($token, $baseQuery, $scope);
            if ($candidates === []) {
                return null;
            }

            $chosenMeta = $this->pickAutopilotCandidate(
                $candidates,
                $driverCleanPaceMs,
                $user->autopilot_mode,
                $user->autopilot_delta_ms ?: 1000,
                $session->avg_track_temp_c
            );

            if ($chosenMeta === null) {
                return null;
            }

            $imported = $this->importSingleLap(
                $user,
                (string) $chosenMeta['id'],
                'garage61_autopilot',
                $session->track,
                $session->car_name
            );

            $session->update(['active_reference_lap_id' => $imported->id]);

            return $imported;
        } catch (\Throwable $e) {
            Log::warning('Garage 61 Autopilot sync warning', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Query Garage 61 respecting the user's configured driver scope:
     * - team_only: strictly user + joined G61 teams
     * - team_then_public: try team + user first; fall back to public community if empty
     * - public_all: query all visible laps (public + team) directly
     *
     * @param  array<string, mixed>  $baseQuery
     * @return array<int, array<string, mixed>>
     */
    private function queryCandidatesByScope(string $token, array $baseQuery, string $scope): array
    {
        if ($scope === 'public_all') {
            return $this->client->searchLaps($token, $baseQuery);
        }

        $teams = $this->client->listTeams($token);
        $teamSlugs = array_filter(array_map(static fn (array $t) => $t['slug'] ?? null, $teams));

        $teamQuery = array_merge($baseQuery, ['drivers' => 'me']);
        if ($teamSlugs !== []) {
            $teamQuery['teams'] = implode(',', $teamSlugs);
        }

        $teamResults = $this->client->searchLaps($token, $teamQuery);
        if ($scope === 'team_only' || $teamResults !== []) {
            return $teamResults;
        }

        // Cascade fallback to all visible public community laps
        return $this->client->searchLaps($token, $baseQuery);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function pickAutopilotCandidate(
        array $candidates,
        int $driverPaceMs,
        string $mode,
        int $targetDeltaMs,
        float $targetTrackTempC
    ): ?array {
        $parsed = [];
        foreach ($candidates as $c) {
            $meta = $this->normalizeLapMeta($c, '', '');
            if ($meta['lap_time_ms'] > 5000) {
                $parsed[] = array_merge($c, ['_meta' => $meta]);
            }
        }
        if ($parsed === []) {
            return null;
        }

        if ($mode === 'progressive' && $driverPaceMs > 0) {
            $idealTargetMs = $driverPaceMs - $targetDeltaMs;
            $minFaster = $driverPaceMs - (int) config('pacefriend.garage61.progressive_max_faster_ms', 1600);
            $maxFaster = $driverPaceMs - (int) config('pacefriend.garage61.progressive_min_faster_ms', 600);

            $inWindow = array_values(array_filter(
                $parsed,
                static fn (array $item) => $item['_meta']['lap_time_ms'] >= $minFaster && $item['_meta']['lap_time_ms'] <= $maxFaster
            ));

            if ($inWindow !== []) {
                usort($inWindow, static function (array $a, array $b) use ($idealTargetMs, $targetTrackTempC) {
                    $scoreA = abs($a['_meta']['lap_time_ms'] - $idealTargetMs) + (abs($a['_meta']['track_temp_c'] - $targetTrackTempC) * 120);
                    $scoreB = abs($b['_meta']['lap_time_ms'] - $idealTargetMs) + (abs($b['_meta']['track_temp_c'] - $targetTrackTempC) * 120);
                    return $scoreA <=> $scoreB;
                });

                return $inWindow[0];
            }

            $faster = array_values(array_filter(
                $parsed,
                static fn (array $item) => $item['_meta']['lap_time_ms'] < ($driverPaceMs - 200)
            ));
            if ($faster !== []) {
                usort($faster, static fn (array $a, array $b) => abs($a['_meta']['lap_time_ms'] - $idealTargetMs) <=> abs($b['_meta']['lap_time_ms'] - $idealTargetMs));
                return $faster[0];
            }
        }

        usort($parsed, static fn (array $a, array $b) => $a['_meta']['lap_time_ms'] <=> $b['_meta']['lap_time_ms']);

        return $parsed[0];
    }

    /**
     * @param  array<string, mixed>  $lap
     * @return array{
     *   id: string,
     *   lap_time_ms: int,
     *   track_temp_c: float,
     *   air_temp_c: float,
     *   is_wet: bool,
     *   driver_name: string,
     *   track_name: string,
     *   track_slug: string,
     *   car_name: string
     * }
     */
    private function normalizeLapMeta(array $lap, string $fallbackTrack, string $fallbackCar): array
    {
        $rawTime = $lap['lapTime'] ?? $lap['lap_time'] ?? $lap['time'] ?? 0;
        $lapTimeMs = ($rawTime > 0 && $rawTime < 2000)
            ? (int) round(((float) $rawTime) * 1000.0)
            : (int) $rawTime;

        $trackName = is_array($lap['track'] ?? null)
            ? trim(($lap['track']['name'] ?? '').' '.($lap['track']['variant'] ?? ''))
            : (string) ($lap['trackName'] ?? $fallbackTrack);

        $carName = is_array($lap['car'] ?? null)
            ? (string) ($lap['car']['name'] ?? $fallbackCar)
            : (string) ($lap['carName'] ?? $fallbackCar);

        $driverName = '';
        if (is_array($lap['driver'] ?? null)) {
            $driverName = trim(($lap['driver']['firstName'] ?? '').' '.($lap['driver']['lastName'] ?? ''));
            if ($driverName === '') {
                $driverName = (string) ($lap['driver']['nickName'] ?? $lap['driver']['slug'] ?? '');
            }
        } else {
            $driverName = (string) ($lap['driverName'] ?? '');
        }

        $trackTemp = (float) ($lap['trackTemp'] ?? $lap['track_temp_c'] ?? 26.0);
        $airTemp = (float) ($lap['airTemp'] ?? $lap['air_temp_c'] ?? 20.0);
        // OpenAPI line 2521: trackWetness is percentage 0..100 (0 = dry, 100 = fully wet)
        $wetnessPct = (int) ($lap['trackWetness'] ?? 0);
        $isWet = $wetnessPct >= 5 || (bool) ($lap['is_wet'] ?? false);

        $fuelLevel = isset($lap['fuelLevel']) && (float) $lap['fuelLevel'] > 0
            ? round((float) $lap['fuelLevel'], 2)
            : null;
        $fuelUsed = isset($lap['fuelUsed']) && (float) $lap['fuelUsed'] > 0
            ? round((float) $lap['fuelUsed'], 3)
            : null;

        return [
            'id' => (string) ($lap['id'] ?? ''),
            'lap_time_ms' => $lapTimeMs,
            'track_temp_c' => round($trackTemp, 1),
            'air_temp_c' => round($airTemp, 1),
            'is_wet' => $isWet,
            'fuel_level_l' => $fuelLevel,
            'fuel_used_l' => $fuelUsed,
            'driver_name' => $driverName,
            'track_name' => $trackName !== '' ? $trackName : $fallbackTrack,
            'track_slug' => Str::slug($trackName !== '' ? $trackName : $fallbackTrack),
            'car_name' => $carName !== '' ? $carName : $fallbackCar,
        ];
    }

    private function requireUserToken(User $user): string
    {
        return $this->oauth->resolveValidAccessToken($user);
    }
}
