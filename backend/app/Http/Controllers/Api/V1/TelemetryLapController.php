<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncGarage61AutopilotJob;
use App\Models\DrivingSession;
use App\Models\Lap;
use App\Models\Track;
use App\Models\User;
use App\Services\Coaching\CoachDiagnosticService;
use App\Services\Coaching\ReferenceMatcherService;
use App\Services\Telemetry\TelemetryNormalizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelemetryLapController extends Controller
{
    public function __construct(
        private readonly TelemetryNormalizerService $normalizer,
        private readonly ReferenceMatcherService $matcher,
        private readonly CoachDiagnosticService $diagnostic,
    ) {}

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
            'sample_rate_hz' => (int) ($validated['sample_rate_hz'] ?? 20),
            'samples' => $validated['samples'],
            'corner_metrics' => $cornerMetrics,
        ]);

        // Update session rolling statistics and dynamic weather-matched reference
        $newLapsCount = $session->laps_count + 1;
        $newCleanCount = $session->clean_laps_count + ($lap->is_clean ? 1 : 0);
        $bestLapMs = $session->best_lap_time_ms;
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

        $session->update([
            'active_reference_lap_id' => $referenceLap?->id,
            'avg_track_temp_c' => $avgTrackTemp,
            'avg_air_temp_c' => $avgAirTemp,
            'is_wet' => $isWet,
            'laps_count' => $newLapsCount,
            'clean_laps_count' => $newCleanCount,
            'best_lap_time_ms' => $bestLapMs,
            'active_coaching_plan' => $diagnosticResult['plan'] ?? $session->active_coaching_plan,
            'last_lap_at' => now(),
        ]);

        // Option C: Trigger non-blocking Garage 61 Progressive Autopilot after HTTP response
        if ($lap->is_clean && $user->autopilot_enabled && filled($user->garage61_token)) {
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
        ], 201);
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

        return response()->json(['status' => 'ok']);
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
