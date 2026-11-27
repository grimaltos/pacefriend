<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncGarage61AutopilotJob;
use App\Models\DrivingSession;
use App\Models\Lap;
use App\Models\Track;
use App\Models\User;
use App\Services\Coaching\CoachDiagnosticService;
use App\Services\Coaching\RadioEngineerService;
use App\Services\Coaching\ReferenceMatcherService;
use App\Services\Garage61\Garage61ImporterService;
use App\Services\Telemetry\TelemetryNormalizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelemetryLapController extends Controller
{
    public function __construct(
        private readonly TelemetryNormalizerService $normalizer,
        private readonly ReferenceMatcherService $matcher,
        private readonly CoachDiagnosticService $diagnostic,
        private readonly RadioEngineerService $radioEngineer,
        private readonly Garage61ImporterService $g61Importer,
    ) {}

    public function briefing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'track_slug' => ['required', 'string', 'max:120'],
            'track_name' => ['nullable', 'string', 'max:180'],
            'track_length_km' => ['nullable', 'numeric', 'min:0.4', 'max:30'],
            'car_name' => ['required', 'string', 'max:160'],
            'session_id' => ['nullable', 'integer', 'min:0'],
            'sub_session_id' => ['nullable', 'integer', 'min:0'],
            'track_temp_c' => ['nullable', 'numeric', 'min:-10', 'max:80'],
            'air_temp_c' => ['nullable', 'numeric', 'min:-20', 'max:60'],
            'is_wet' => ['nullable', 'boolean'],
            'fuel_level_l' => ['nullable', 'numeric', 'min:0', 'max:250'],
            'reason' => ['nullable', 'in:session_start,conditions_changed'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_client_seen_at' => now()])->save();

        $track = Track::resolveOrCreateCurated(
            $validated['track_slug'],
            $validated['track_name'] ?? $validated['track_slug'],
            (float) ($validated['track_length_km'] ?? 5.0)
        );

        $trackTempC = isset($validated['track_temp_c']) && (float) $validated['track_temp_c'] > 0
            ? (float) $validated['track_temp_c']
            : 26.0;
        $airTempC = isset($validated['air_temp_c']) && (float) $validated['air_temp_c'] > 0
            ? (float) $validated['air_temp_c']
            : 20.0;
        $isWet = (bool) ($validated['is_wet'] ?? false);
        $fuelLevelL = isset($validated['fuel_level_l']) ? (float) $validated['fuel_level_l'] : null;

        $referenceLap = $this->matcher->selectBestReference(
            $user->id,
            $track->id,
            $validated['car_name'],
            $trackTempC,
            $airTempC,
            $isWet
        );

        $session = $this->resolveActiveDrivingSession(
            $user,
            $track,
            $validated['car_name'],
            (int) ($validated['session_id'] ?? 0),
            (int) ($validated['sub_session_id'] ?? 0),
            $trackTempC,
            $airTempC,
            $isWet,
            $referenceLap?->id
        );

        $callouts = $this->radioEngineer->buildBriefing(
            $user,
            $track,
            $trackTempC,
            $airTempC,
            $isWet,
            $fuelLevelL,
            $referenceLap,
            (string) ($validated['reason'] ?? 'session_start')
        );

        return response()->json([
            'status' => 'ok',
            'driving_session_id' => $session->id,
            'matched_reference_lap_id' => $referenceLap?->id,
            'radio_callouts' => $callouts,
            'audio_settings' => $this->clientAudioSettings($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'track_slug' => ['required', 'string', 'max:120'],
            'track_name' => ['nullable', 'string', 'max:180'],
            'track_length_km' => ['nullable', 'numeric', 'min:0.4', 'max:30'],
            'car_name' => ['required', 'string', 'max:160'],
            'session_id' => ['nullable', 'integer', 'min:0'],
            'sub_session_id' => ['nullable', 'integer', 'min:0'],
            'lap_number' => ['required', 'integer', 'min:1'],
            'lap_time_ms' => ['required', 'integer', 'min:5000', 'max:1200000'],
            'is_clean' => ['required', 'boolean'],
            'incident_delta' => ['nullable', 'integer', 'min:0'],
            'track_temp_c' => ['nullable', 'numeric', 'min:-10', 'max:80'],
            'air_temp_c' => ['nullable', 'numeric', 'min:-20', 'max:60'],
            'is_wet' => ['nullable', 'boolean'],
            'fuel_level_l' => ['nullable', 'numeric', 'min:0', 'max:250'],
            'fuel_used_l' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'sample_rate_hz' => ['nullable', 'integer', 'min:1', 'max:360'],
            'samples' => ['required', 'array', 'min:5'],
            'samples.*.t_ms' => ['required', 'integer', 'min:0'],
            'samples.*.pct' => ['required', 'numeric', 'min:0', 'max:1'],
            'samples.*.speed' => ['required', 'numeric'],
            'samples.*.throttle' => ['required', 'numeric'],
            'samples.*.brake' => ['required', 'numeric'],
            'samples.*.gear' => ['required', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_client_seen_at' => now()])->save();

        $track = Track::resolveOrCreateCurated(
            $validated['track_slug'],
            $validated['track_name'] ?? $validated['track_slug'],
            (float) ($validated['track_length_km'] ?? 5.0)
        );

        $trackTempC = isset($validated['track_temp_c']) && (float) $validated['track_temp_c'] > 0
            ? (float) $validated['track_temp_c']
            : 26.0;
        $airTempC = isset($validated['air_temp_c']) && (float) $validated['air_temp_c'] > 0
            ? (float) $validated['air_temp_c']
            : 20.0;
        $isWet = (bool) ($validated['is_wet'] ?? false);
        $fuelLevelL = isset($validated['fuel_level_l']) && (float) $validated['fuel_level_l'] >= 0
            ? round((float) $validated['fuel_level_l'], 2)
            : null;
        $fuelUsedL = isset($validated['fuel_used_l']) && (float) $validated['fuel_used_l'] > 0.05
            ? round((float) $validated['fuel_used_l'], 3)
            : null;

        $referenceLap = $this->matcher->selectBestReference(
            $user->id,
            $track->id,
            $validated['car_name'],
            $trackTempC,
            $airTempC,
            $isWet
        );

        $session = $this->resolveActiveDrivingSession(
            $user,
            $track,
            $validated['car_name'],
            (int) ($validated['session_id'] ?? 0),
            (int) ($validated['sub_session_id'] ?? 0),
            $trackTempC,
            $airTempC,
            $isWet,
            $referenceLap?->id
        );

        $previousBestMs = $session->best_lap_time_ms;
        $previousPlan = is_array($session->active_coaching_plan) ? $session->active_coaching_plan : null;
        $previousRef = $session->activeReferenceLap;
        $radioFlags = is_array($previousPlan['_radio_flags'] ?? null) ? $previousPlan['_radio_flags'] : [];

        // Reset fuel one-shot warnings if driver refueled in pits
        $lastLapFuel = Lap::query()
            ->where('driving_session_id', $session->id)
            ->whereNotNull('fuel_level_l')
            ->latest('id')
            ->value('fuel_level_l');
        if ($fuelLevelL !== null && $lastLapFuel !== null && $fuelLevelL > ((float) $lastLapFuel + 2.0)) {
            $radioFlags['fuel_3_warned'] = false;
            $radioFlags['fuel_1_warned'] = false;
        }

        // If no reference exists yet and driver completes their 1st clean lap with Autopilot active,
        // fetch the target synchronously so the engineer can announce it immediately on the straight.
        if ((bool) $validated['is_clean'] && $referenceLap === null && $user->autopilot_enabled && filled($user->garage61_token)) {
            $session->avg_track_temp_c = $trackTempC;
            $session->is_wet = $isWet;
            $synced = $this->g61Importer->syncAutopilotForSession($session, (int) $validated['lap_time_ms']);
            if ($synced !== null) {
                $referenceLap = $synced;
            }
        }

        $rawCorners = $this->normalizer->extractCornerBaselines(
            $validated['samples'],
            $track->corners ?? [],
            (int) $validated['lap_time_ms']
        );

        $cornerMetrics = $referenceLap !== null
            ? $this->normalizer->compareAgainstReference($rawCorners, $referenceLap->corner_baselines ?? [], $track->length_km)
            : $rawCorners;

        $lap = Lap::query()->create([
            'driving_session_id' => $session->id,
            'user_id' => $user->id,
            'track_id' => $track->id,
            'reference_lap_id' => $referenceLap?->id,
            'car_name' => $validated['car_name'],
            'lap_number' => (int) $validated['lap_number'],
            'lap_time_ms' => (int) $validated['lap_time_ms'],
            'is_clean' => (bool) $validated['is_clean'],
            'incident_delta' => (int) ($validated['incident_delta'] ?? 0),
            'track_temp_c' => $trackTempC,
            'air_temp_c' => $airTempC,
            'is_wet' => $isWet,
            'fuel_level_l' => $fuelLevelL,
            'fuel_used_l' => $fuelUsedL,
            'sample_rate_hz' => (int) ($validated['sample_rate_hz'] ?? 20),
            'samples' => $validated['samples'],
            'corner_metrics' => $cornerMetrics,
        ]);
        $lap->setRelation('track', $track);

        // Update session rolling statistics and dynamic weather-matched reference
        $newLapsCount = $session->laps_count + 1;
        $newCleanCount = $session->clean_laps_count + ($lap->is_clean ? 1 : 0);
        $bestLapMs = $previousBestMs;
        if ($lap->is_clean && ($bestLapMs === null || $lap->lap_time_ms < $bestLapMs)) {
            $bestLapMs = $lap->lap_time_ms;
        }

        $avgTrackTemp = round((($session->avg_track_temp_c * $session->laps_count) + $trackTempC) / $newLapsCount, 2);
        $avgAirTemp = round((($session->avg_air_temp_c * $session->laps_count) + $airTempC) / $newLapsCount, 2);

        $diagnosticResult = $this->diagnostic->buildCoachingPlan(
            $user,
            $track,
            $validated['car_name'],
            $referenceLap
        );

        $avgFuelUsed = Lap::query()
            ->where('driving_session_id', $session->id)
            ->where('is_clean', true)
            ->whereNotNull('fuel_used_l')
            ->where('fuel_used_l', '>', 0.1)
            ->avg('fuel_used_l');

        $session->clean_laps_count = $newCleanCount;
        $radioCallouts = $this->radioEngineer->buildLapCrossingCallouts(
            $user,
            $session,
            $lap,
            $previousBestMs,
            $previousPlan,
            $previousRef,
            $referenceLap,
            $avgFuelUsed !== null ? (float) $avgFuelUsed : $fuelUsedL,
            $radioFlags
        );

        $storedPlan = $diagnosticResult['plan'] ?? $previousPlan ?? [];
        if ($radioFlags !== []) {
            $storedPlan['_radio_flags'] = $radioFlags;
        }

        $session->update([
            'active_reference_lap_id' => $referenceLap?->id,
            'avg_track_temp_c' => $avgTrackTemp,
            'avg_air_temp_c' => $avgAirTemp,
            'is_wet' => $isWet,
            'laps_count' => $newLapsCount,
            'clean_laps_count' => $newCleanCount,
            'best_lap_time_ms' => $bestLapMs,
            'active_coaching_plan' => $storedPlan !== [] ? $storedPlan : null,
            'last_lap_at' => now(),
        ]);

        // Option C: Trigger background Garage 61 Progressive Autopilot level-up check
        if ($lap->is_clean && $referenceLap !== null && $user->autopilot_enabled && filled($user->garage61_token)) {
            SyncGarage61AutopilotJob::dispatchAfterResponse(
                $session->id,
                $bestLapMs ?? $lap->lap_time_ms
            );
        }

        return response()->json([
            'status' => 'ok',
            'lap_stored' => true,
            'lap_id' => $lap->id,
            'driving_session_id' => $session->id,
            'matched_reference_lap_id' => $referenceLap?->id,
            'clean_laps_window' => $diagnosticResult['clean_laps_count'],
            'coaching_plan' => $diagnosticResult['plan'],
            'radio_callouts' => $radioCallouts,
            'audio_settings' => $this->clientAudioSettings($user),
        ], 201);
    }

    public function pitStop(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lf_temp_c' => ['required', 'numeric', 'min:0', 'max:200'],
            'rf_temp_c' => ['required', 'numeric', 'min:0', 'max:200'],
            'lr_temp_c' => ['required', 'numeric', 'min:0', 'max:200'],
            'rr_temp_c' => ['required', 'numeric', 'min:0', 'max:200'],
            'laps_completed' => ['nullable', 'integer', 'min:0'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_client_seen_at' => now()])->save();

        $callouts = $this->radioEngineer->buildPitTyreDebrief($user, [
            'lf' => (float) $validated['lf_temp_c'],
            'rf' => (float) $validated['rf_temp_c'],
            'lr' => (float) $validated['lr_temp_c'],
            'rr' => (float) $validated['rr_temp_c'],
        ], (int) ($validated['laps_completed'] ?? 1));

        return response()->json([
            'status' => 'ok',
            'radio_callouts' => $callouts,
            'audio_settings' => $this->clientAudioSettings($user),
        ]);
    }

    public function radioTest(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $preview = $this->radioEngineer->buildPreviewClip($user);

        return response()->json([
            'status' => 'ok',
            'radio_callouts' => [[
                'id' => 'tray_radio_test_'.substr($preview['sha256'], 0, 8),
                'text' => $preview['text'],
                'audio_url' => $preview['url'],
                'audio_sha256' => $preview['sha256'],
            ]],
            'audio_settings' => $this->clientAudioSettings($user),
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['nullable', 'string', 'max:40'],
            'track_name' => ['nullable', 'string', 'max:180'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $prefs = $user->preferences ?? [];
        $prefs['client_state'] = $validated['state'] ?? 'waiting_iracing';
        $prefs['client_track'] = $validated['track_name'] ?? '';

        $user->forceFill([
            'last_client_seen_at' => now(),
            'preferences' => $prefs,
        ])->save();

        return response()->json([
            'status' => 'ok',
            'audio_settings' => $this->clientAudioSettings($user),
        ]);
    }

    public function endSession(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $prefs = $user->preferences ?? [];
        $prefs['client_state'] = 'offline';
        $prefs['client_track'] = '';
        $user->forceFill(['preferences' => $prefs])->save();

        $openSession = DrivingSession::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        if ($openSession !== null) {
            $openSession->update(['ended_at' => now()]);
        }

        return response()->json([
            'status' => 'ok',
            'ended_session_id' => $openSession?->id,
        ]);
    }

    /**
     * @return array{volume: int}
     */
    private function clientAudioSettings(User $user): array
    {
        $radio = $user->radioSettings();
        return [
            'volume' => $radio['volume'],
        ];
    }

    private function resolveActiveDrivingSession(
        User $user,
        Track $track,
        string $carName,
        int $iracingSessionId,
        int $iracingSubsessionId,
        float $trackTempC,
        float $airTempC,
        bool $isWet,
        ?int $referenceLapId
    ): DrivingSession {
        $query = DrivingSession::query()
            ->where('user_id', $user->id)
            ->where('track_id', $track->id)
            ->where('car_name', $carName)
            ->whereNull('ended_at');

        if ($iracingSessionId > 0 || $iracingSubsessionId > 0) {
            $query->where('iracing_session_id', $iracingSessionId)
                ->where('iracing_subsession_id', $iracingSubsessionId);
        } else {
            $query->where('updated_at', '>=', now()->subMinutes(30));
        }

        $existing = $query->latest('started_at')->first();
        if ($existing !== null) {
            return $existing;
        }

        return DrivingSession::query()->create([
            'user_id' => $user->id,
            'track_id' => $track->id,
            'active_reference_lap_id' => $referenceLapId,
            'car_name' => $carName,
            'iracing_session_id' => $iracingSessionId > 0 ? $iracingSessionId : null,
            'iracing_subsession_id' => $iracingSubsessionId > 0 ? $iracingSubsessionId : null,
            'avg_track_temp_c' => $trackTempC,
            'avg_air_temp_c' => $airTempC,
            'is_wet' => $isWet,
            'started_at' => now(),
            'last_lap_at' => now(),
        ]);
    }
}
