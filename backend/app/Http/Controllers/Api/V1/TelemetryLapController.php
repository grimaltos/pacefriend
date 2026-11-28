<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncGarage61AutopilotJob;
use App\Models\DrivingSession;
use App\Models\Lap;
use App\Models\ReferenceLap;
use App\Models\Track;
use App\Models\User;
use App\Services\Coaching\CoachDiagnosticService;
use App\Services\Coaching\CommunityKnowledgeService;
use App\Services\Coaching\NaturalVoiceEngineerService;
use App\Services\Coaching\RaceStrategyService;
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
        private readonly RaceStrategyService $raceStrategy,
        private readonly NaturalVoiceEngineerService $voiceEngineer,
        private readonly Garage61ImporterService $g61Importer,
        private readonly CommunityKnowledgeService $communityKnowledge,
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
            'sector_splits' => ['nullable', 'array', 'max:24'],
            'sector_splits.*' => ['numeric', 'min:0', 'max:1'],
            'reason' => ['nullable', 'in:session_start,conditions_changed'],
            'race_context' => ['nullable', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_client_seen_at' => now()])->save();

        $track = Track::resolveOrCreateCurated(
            $validated['track_slug'],
            $validated['track_name'] ?? $validated['track_slug'],
            (float) ($validated['track_length_km'] ?? 5.0)
        );
        if (! empty($validated['sector_splits'])) {
            $track->syncSectorSplits($validated['sector_splits']);
        }

        $trackTempC = isset($validated['track_temp_c']) && (float) $validated['track_temp_c'] > 0
            ? (float) $validated['track_temp_c']
            : 26.0;
        $airTempC = isset($validated['air_temp_c']) && (float) $validated['air_temp_c'] > 0
            ? (float) $validated['air_temp_c']
            : 20.0;
        $isWet = (bool) ($validated['is_wet'] ?? false);
        $fuelLevelL = isset($validated['fuel_level_l']) ? (float) $validated['fuel_level_l'] : null;

        $match = $this->matcher->matchWithDiagnostics(
            $user->id,
            $track->id,
            $validated['car_name'],
            $trackTempC,
            $airTempC,
            $isWet
        );
        $referenceLap = $match['reference'];

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

        $raceCtx = is_array($validated['race_context'] ?? null) ? $validated['race_context'] : [];
        $sessionType = (string) ($raceCtx['session_type'] ?? 'practice');
        $reason = (string) ($validated['reason'] ?? 'session_start');
        $pitCommands = [];

        if ($reason === 'session_start' && in_array($sessionType, ['race', 'qualify'], true)) {
            $raceBrief = $this->raceStrategy->buildSessionBriefing(
                $user,
                $track,
                $trackTempC,
                $airTempC,
                $isWet,
                $fuelLevelL,
                $referenceLap,
                $raceCtx
            );
            $callouts = $raceBrief['callouts'];
            $pitCommands = $raceBrief['pit_commands'];
        } else {
            $callouts = $this->radioEngineer->buildBriefing(
                $user,
                $track,
                $trackTempC,
                $airTempC,
                $isWet,
                $fuelLevelL,
                $referenceLap,
                $reason,
                $match['discarded_reason'],
                $match['discarded_ref']
            );
        }

        return response()->json([
            'status' => 'ok',
            'driving_session_id' => $session->id,
            'matched_reference_lap_id' => $referenceLap?->id,
            'radio_callouts' => $callouts,
            'pit_commands' => $pitCommands,
        ]);
    }

    /**
     * Ingest a single completed partial/sector in real time (mid-lap or flying out-lap sectors),
     * updating per-corner diagnostics, optimal theoretical lap, and coaching plan immediately.
     */
    public function storeSector(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'track_slug' => ['required', 'string', 'max:120'],
            'track_name' => ['nullable', 'string', 'max:180'],
            'track_length_km' => ['nullable', 'numeric', 'min:0.4', 'max:30'],
            'car_name' => ['required', 'string', 'max:160'],
            'session_id' => ['nullable', 'integer', 'min:0'],
            'sub_session_id' => ['nullable', 'integer', 'min:0'],
            'lap_number' => ['required', 'integer', 'min:0'],
            'sector_number' => ['required', 'integer', 'min:1', 'max:24'],
            'sector_start_pct' => ['required', 'numeric', 'min:0', 'max:1'],
            'sector_end_pct' => ['required', 'numeric', 'min:0', 'max:1'],
            'sector_time_ms' => ['required', 'integer', 'min:500', 'max:600000'],
            'is_clean' => ['required', 'boolean'],
            'track_temp_c' => ['nullable', 'numeric', 'min:-10', 'max:80'],
            'air_temp_c' => ['nullable', 'numeric', 'min:-20', 'max:60'],
            'is_wet' => ['nullable', 'boolean'],
            'sector_splits' => ['nullable', 'array', 'max:24'],
            'sector_splits.*' => ['numeric', 'min:0', 'max:1'],
            'race_context' => ['nullable', 'array'],
            'samples' => ['required', 'array', 'min:3'],
            'samples.*.t_ms' => ['required', 'integer', 'min:0'],
            'samples.*.pct' => ['required', 'numeric', 'min:0', 'max:1'],
            'samples.*.speed' => ['required', 'numeric'],
            'samples.*.throttle' => ['required', 'numeric'],
            'samples.*.brake' => ['required', 'numeric'],
            'samples.*.gear' => ['required', 'integer'],
            'samples.*.flags' => ['nullable', 'integer', 'min:0'],
            'samples.*.lat' => ['nullable', 'numeric'],
            'samples.*.lon' => ['nullable', 'numeric'],
            'samples.*.steer' => ['nullable', 'numeric'],
            'samples.*.yaw' => ['nullable', 'numeric'],
            'samples.*.lat_g' => ['nullable', 'numeric'],
            'samples.*.long_g' => ['nullable', 'numeric'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_client_seen_at' => now()])->save();

        $track = Track::resolveOrCreateCurated(
            $validated['track_slug'],
            $validated['track_name'] ?? $validated['track_slug'],
            (float) ($validated['track_length_km'] ?? 5.0)
        );
        if (! empty($validated['sector_splits'])) {
            $track->syncSectorSplits($validated['sector_splits']);
        }

        $trackTempC = isset($validated['track_temp_c']) && (float) $validated['track_temp_c'] > 0
            ? (float) $validated['track_temp_c']
            : 26.0;
        $airTempC = isset($validated['air_temp_c']) && (float) $validated['air_temp_c'] > 0
            ? (float) $validated['air_temp_c']
            : 20.0;
        $isWet = (bool) ($validated['is_wet'] ?? false);

        $session = $this->resolveActiveDrivingSession(
            $user,
            $track,
            $validated['car_name'],
            (int) ($validated['session_id'] ?? 0),
            (int) ($validated['sub_session_id'] ?? 0),
            $trackTempC,
            $airTempC,
            $isWet,
            null
        );

        $referenceLap = $this->matcher->selectBestReference(
            $user->id,
            $track->id,
            $validated['car_name'],
            $trackTempC,
            $airTempC,
            $isWet,
            $session->best_lap_time_ms
        );

        $sectorNum = (int) $validated['sector_number'];
        $sectorTimeMs = (int) $validated['sector_time_ms'];
        $sectorIsClean = (bool) $validated['is_clean'];
        $trackSectors = $track->effectiveSectors();

        $bestSectors = is_array($session->best_sectors_ms) ? $session->best_sectors_ms : [];
        $previousBestSectorMs = isset($bestSectors[(string) $sectorNum]) ? (int) $bestSectors[(string) $sectorNum] : null;

        if ($sectorIsClean && ($previousBestSectorMs === null || $sectorTimeMs < $previousBestSectorMs)) {
            $bestSectors[(string) $sectorNum] = $sectorTimeMs;
        }

        $optimalLapMs = $this->computeOptimalLapMs($bestSectors, $trackSectors);
        $newAutopilotRef = false;

        // Unlock Garage 61 Autopilot even if driver has 0 full clean laps, as soon as all clean partials form an Optimal Lap!
        if ($referenceLap === null && $optimalLapMs !== null && $user->autopilot_enabled && filled($user->garage61_token)) {
            $session->avg_track_temp_c = $trackTempC;
            $session->is_wet = $isWet;
            $synced = $this->g61Importer->syncAutopilotForSession($session, $optimalLapMs);
            if ($synced !== null) {
                $referenceLap = $synced;
                $newAutopilotRef = true;
            }
        }

        // Extract & compare corners contained within this partial
        $partialCorners = $this->normalizer->extractCornerBaselines(
            $validated['samples'],
            $track->corners ?? [],
            0,
            $track->length_km,
            $sectorIsClean
        );
        if ($referenceLap !== null) {
            $partialCorners = $this->normalizer->compareAgainstReference(
                $partialCorners,
                $referenceLap->corner_baselines ?? [],
                $track->length_km
            );
        }

        $targetSectorMap = $referenceLap !== null
            ? $this->normalizer->resolveReferenceSectorMap($referenceLap, $trackSectors, $track->length_km)
            : [];
        $targetSectorMs = $targetSectorMap[$sectorNum] ?? null;

        $sectorEntry = [
            'sector' => $sectorNum,
            'start_pct' => (float) $validated['sector_start_pct'],
            'end_pct' => (float) $validated['sector_end_pct'],
            'time_ms' => $sectorTimeMs,
            'is_clean' => $sectorIsClean,
        ];
        if ($targetSectorMs !== null) {
            $sectorEntry['target_time_ms'] = $targetSectorMs;
            $sectorEntry['delta_ms'] = $sectorTimeMs - $targetSectorMs;
        }

        // Upsert in-progress Lap row for this lap_number so corners/partials are immediately queryable
        $lap = Lap::query()
            ->where('driving_session_id', $session->id)
            ->where('lap_number', (int) $validated['lap_number'])
            ->first();

        if ($lap === null) {
            $lap = Lap::query()->create([
                'driving_session_id' => $session->id,
                'user_id' => $user->id,
                'track_id' => $track->id,
                'reference_lap_id' => $referenceLap?->id,
                'car_name' => $validated['car_name'],
                'lap_number' => (int) $validated['lap_number'],
                'lap_time_ms' => $sectorTimeMs,
                'is_clean' => $sectorIsClean,
                'is_complete' => false,
                'incident_delta' => $sectorIsClean ? 0 : 1,
                'track_temp_c' => $trackTempC,
                'air_temp_c' => $airTempC,
                'is_wet' => $isWet,
                'samples' => $validated['samples'],
                'corner_metrics' => $partialCorners,
                'sector_metrics' => [$sectorEntry],
            ]);
        } elseif (! $lap->is_complete) {
            $mergedCorners = $this->mergeByKey($lap->corner_metrics ?? [], $partialCorners, 'turn');
            $mergedSectors = $this->mergeByKey($lap->sector_metrics ?? [], [$sectorEntry], 'sector');
            $lap->update([
                'reference_lap_id' => $referenceLap?->id ?? $lap->reference_lap_id,
                'lap_time_ms' => array_sum(array_column($mergedSectors, 'time_ms')),
                'is_clean' => $lap->is_clean && $sectorIsClean,
                'corner_metrics' => $mergedCorners,
                'sector_metrics' => $mergedSectors,
            ]);
        }

        // Rebuild coaching plan mid-lap from all clean corner passes (External Target OR Session Self-Reference)!
        $diagnosticResult = $this->diagnostic->buildCoachingPlan(
            $user,
            $track,
            $validated['car_name'],
            $referenceLap,
            $session->id
        );

        $previousPlan = is_array($session->active_coaching_plan) ? $session->active_coaching_plan : [];
        $storedPlan = $diagnosticResult['plan'] ?? $previousPlan;
        if (isset($previousPlan['_radio_flags']) && is_array($storedPlan)) {
            $storedPlan['_radio_flags'] = $previousPlan['_radio_flags'];
        }
        if (isset($previousPlan['_pending_pit']) && is_array($storedPlan)) {
            $storedPlan['_pending_pit'] = $previousPlan['_pending_pit'];
        }

        $session->update([
            'active_reference_lap_id' => $referenceLap?->id,
            'clean_sectors_count' => $session->clean_sectors_count + ($sectorIsClean ? 1 : 0),
            'best_sectors_ms' => $bestSectors !== [] ? $bestSectors : null,
            'optimal_lap_time_ms' => $optimalLapMs,
            'active_coaching_plan' => $storedPlan !== [] ? $storedPlan : null,
            'last_lap_at' => now(),
        ]);

        $callouts = $this->radioEngineer->buildSectorCrossingCallouts(
            $user,
            (int) $validated['lap_number'],
            $sectorNum,
            $sectorTimeMs,
            $sectorIsClean,
            $previousBestSectorMs,
            $targetSectorMs,
            $newAutopilotRef,
            $referenceLap
        );

        return response()->json([
            'status' => 'ok',
            'sector_stored' => true,
            'driving_session_id' => $session->id,
            'optimal_lap_time_ms' => $optimalLapMs,
            'clean_laps_window' => $diagnosticResult['clean_laps_count'],
            'coaching_plan' => $diagnosticResult['plan'],
            'radio_callouts' => $callouts,
            'audio_settings' => $this->clientAudioSettings($user),
        ], 201);
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
            'sector_splits' => ['nullable', 'array', 'max:24'],
            'sector_splits.*' => ['numeric', 'min:0', 'max:1'],
            'sample_rate_hz' => ['nullable', 'integer', 'min:1', 'max:360'],
            'race_context' => ['nullable', 'array'],
            'samples' => ['required', 'array', 'min:5'],
            'samples.*.t_ms' => ['required', 'integer', 'min:0'],
            'samples.*.pct' => ['required', 'numeric', 'min:0', 'max:1'],
            'samples.*.speed' => ['required', 'numeric'],
            'samples.*.throttle' => ['required', 'numeric'],
            'samples.*.brake' => ['required', 'numeric'],
            'samples.*.gear' => ['required', 'integer'],
            'samples.*.flags' => ['nullable', 'integer', 'min:0'],
            'samples.*.lat' => ['nullable', 'numeric'],
            'samples.*.lon' => ['nullable', 'numeric'],
            'samples.*.steer' => ['nullable', 'numeric'],
            'samples.*.yaw' => ['nullable', 'numeric'],
            'samples.*.lat_g' => ['nullable', 'numeric'],
            'samples.*.long_g' => ['nullable', 'numeric'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_client_seen_at' => now()])->save();

        $track = Track::resolveOrCreateCurated(
            $validated['track_slug'],
            $validated['track_name'] ?? $validated['track_slug'],
            (float) ($validated['track_length_km'] ?? 5.0)
        );
        if (! empty($validated['sector_splits'])) {
            $track->syncSectorSplits($validated['sector_splits']);
        }

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

        $session = $this->resolveActiveDrivingSession(
            $user,
            $track,
            $validated['car_name'],
            (int) ($validated['session_id'] ?? 0),
            (int) ($validated['sub_session_id'] ?? 0),
            $trackTempC,
            $airTempC,
            $isWet,
            null
        );

        $previousBestMs = $session->best_lap_time_ms;
        $currentCleanMs = (bool) $validated['is_clean'] ? (int) $validated['lap_time_ms'] : null;
        $driverBestMs = ($previousBestMs !== null && $currentCleanMs !== null)
            ? min($previousBestMs, $currentCleanMs)
            : ($currentCleanMs ?? $previousBestMs);

        $referenceLap = $this->matcher->selectBestReference(
            $user->id,
            $track->id,
            $validated['car_name'],
            $trackTempC,
            $airTempC,
            $isWet,
            $driverBestMs
        );

        $previousPlan = is_array($session->active_coaching_plan) ? $session->active_coaching_plan : null;
        $previousRef = $session->activeReferenceLap;
        $radioFlags = is_array($previousPlan['_radio_flags'] ?? null) ? $previousPlan['_radio_flags'] : [];

        // Reset fuel one-shot warnings if driver refueled in pits
        $lastLapFuel = Lap::query()
            ->where('driving_session_id', $session->id)
            ->where('is_complete', true)
            ->whereNotNull('fuel_level_l')
            ->latest('id')
            ->value('fuel_level_l');
        if ($fuelLevelL !== null && $lastLapFuel !== null && $fuelLevelL > ((float) $lastLapFuel + 2.0)) {
            $radioFlags['fuel_3_warned'] = false;
            $radioFlags['fuel_1_warned'] = false;
        }

        $trackSectors = $track->effectiveSectors();
        $rawSectors = $this->normalizer->extractSectorMetrics(
            $validated['samples'],
            $trackSectors,
            (int) $validated['lap_time_ms'],
            $track->length_km,
            (bool) $validated['is_clean']
        );

        // Update session best_sectors_ms & optimal_lap_time_ms from every clean partial in this lap!
        $bestSectors = is_array($session->best_sectors_ms) ? $session->best_sectors_ms : [];
        $newlyAddedCleanSectors = 0;
        foreach ($rawSectors as $sm) {
            if (! empty($sm['is_clean'])) {
                $newlyAddedCleanSectors++;
                $sKey = (string) $sm['sector'];
                if (! isset($bestSectors[$sKey]) || (int) $sm['time_ms'] < (int) $bestSectors[$sKey]) {
                    $bestSectors[$sKey] = (int) $sm['time_ms'];
                }
            }
        }
        $optimalLapMs = $this->computeOptimalLapMs($bestSectors, $trackSectors);

        // Unlock Autopilot synchronously if no reference exists yet and we have either a clean lap OR a full Optimal Lap from partials!
        $effectiveDriverPaceMs = (bool) $validated['is_clean']
            ? (int) $validated['lap_time_ms']
            : $optimalLapMs;

        if ($referenceLap === null && $effectiveDriverPaceMs !== null && $user->autopilot_enabled && filled($user->garage61_token)) {
            $session->avg_track_temp_c = $trackTempC;
            $session->is_wet = $isWet;
            $synced = $this->g61Importer->syncAutopilotForSession($session, $effectiveDriverPaceMs);
            if ($synced !== null) {
                $referenceLap = $synced;
            }
        }

        $raceCtx = is_array($validated['race_context'] ?? null) ? $validated['race_context'] : [];
        $sessionType = (string) ($raceCtx['session_type'] ?? 'practice');
        $inTraffic = ! empty($raceCtx['in_traffic']);
        $isCleanForTechnical = (bool) $validated['is_clean'] && ! $inTraffic;

        $rawCorners = $this->normalizer->extractCornerBaselines(
            $validated['samples'],
            $track->corners ?? [],
            (int) $validated['lap_time_ms'],
            $track->length_km,
            $isCleanForTechnical
        );

        $cornerMetrics = $referenceLap !== null
            ? $this->normalizer->compareAgainstReference($rawCorners, $referenceLap->corner_baselines ?? [], $track->length_km)
            : $rawCorners;

        if ($inTraffic) {
            foreach ($cornerMetrics as &$cm) {
                $cm['is_clean'] = false;
                $cm['in_traffic'] = true;
            }
            unset($cm);
        }

        $sectorMetrics = $this->normalizer->compareSectorsAgainstReference(
            $rawSectors,
            $referenceLap,
            $trackSectors,
            $track->length_km
        );

        // Finalize in-progress partial Lap row if one was created mid-lap by storeSector, otherwise create
        $existingPartial = Lap::query()
            ->where('driving_session_id', $session->id)
            ->where('lap_number', (int) $validated['lap_number'])
            ->where('is_complete', false)
            ->first();

        $lapPayload = [
            'driving_session_id' => $session->id,
            'user_id' => $user->id,
            'track_id' => $track->id,
            'reference_lap_id' => $referenceLap?->id,
            'car_name' => $validated['car_name'],
            'lap_number' => (int) $validated['lap_number'],
            'lap_time_ms' => (int) $validated['lap_time_ms'],
            'is_clean' => (bool) $validated['is_clean'],
            'is_complete' => true,
            'incident_delta' => (int) ($validated['incident_delta'] ?? 0),
            'track_temp_c' => $trackTempC,
            'air_temp_c' => $airTempC,
            'is_wet' => $isWet,
            'fuel_level_l' => $fuelLevelL,
            'fuel_used_l' => $fuelUsedL,
            'sample_rate_hz' => (int) ($validated['sample_rate_hz'] ?? 20),
            'samples' => $validated['samples'],
            'corner_metrics' => $cornerMetrics,
            'sector_metrics' => $sectorMetrics,
        ];

        if ($existingPartial !== null) {
            $existingPartial->update($lapPayload);
            $lap = $existingPartial;
        } else {
            $lap = Lap::query()->create($lapPayload);
        }
        $lap->setRelation('track', $track);

        // Feed PaceFriend's collective anonymized pool, track auto-discovery, G-profile & coaching feedback loop!
        $this->communityKnowledge->recordCoachingOutcome($lap, $previousPlan);
        if (! $inTraffic) {
            $this->communityKnowledge->ingestCleanLap($lap, $track);
        }

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
            $referenceLap,
            $session->id
        );

        $avgFuelUsed = Lap::query()
            ->where('driving_session_id', $session->id)
            ->where('is_complete', true)
            ->where('is_clean', true)
            ->whereNotNull('fuel_used_l')
            ->where('fuel_used_l', '>', 0.1)
            ->avg('fuel_used_l');

        $straightBrakeDragMs = $this->normalizer->detectStraightBrakeDragMs($validated['samples']);

        $session->clean_laps_count = $newCleanCount;
        $pitCommands = [];
        $pendingPit = is_array($previousPlan['_pending_pit'] ?? null) ? $previousPlan['_pending_pit'] : null;

        if ($sessionType === 'race') {
            $raceEval = $this->raceStrategy->evaluateRaceLap(
                $user,
                $session,
                $track,
                $lap,
                $previousBestMs,
                $avgFuelUsed !== null ? (float) $avgFuelUsed : $fuelUsedL,
                $raceCtx,
                $radioFlags,
                $straightBrakeDragMs
            );
            $radioCallouts = $raceEval['callouts'];
            $pitCommands = $raceEval['pit_commands'];
            if ($raceEval['pending_pit'] !== null) {
                $pendingPit = $raceEval['pending_pit'];
            }
        } else {
            $radioCallouts = $this->radioEngineer->buildLapCrossingCallouts(
                $user,
                $session,
                $lap,
                $previousBestMs,
                $previousPlan,
                $previousRef,
                $referenceLap,
                $avgFuelUsed !== null ? (float) $avgFuelUsed : $fuelUsedL,
                $radioFlags,
                $straightBrakeDragMs
            );
        }

        $storedPlan = $diagnosticResult['plan'] ?? $previousPlan ?? [];
        if ($radioFlags !== []) {
            $storedPlan['_radio_flags'] = $radioFlags;
        }
        if ($pendingPit !== null) {
            $storedPlan['_pending_pit'] = $pendingPit;
        } else {
            unset($storedPlan['_pending_pit']);
        }

        $cleanSectorsTotal = $existingPartial !== null
            ? max($session->clean_sectors_count, $newlyAddedCleanSectors)
            : ($session->clean_sectors_count + $newlyAddedCleanSectors);

        $session->update([
            'active_reference_lap_id' => $referenceLap?->id,
            'avg_track_temp_c' => $avgTrackTemp,
            'avg_air_temp_c' => $avgAirTemp,
            'is_wet' => $isWet,
            'laps_count' => $newLapsCount,
            'clean_laps_count' => $newCleanCount,
            'clean_sectors_count' => $cleanSectorsTotal,
            'best_lap_time_ms' => $bestLapMs,
            'optimal_lap_time_ms' => $optimalLapMs,
            'best_sectors_ms' => $bestSectors !== [] ? $bestSectors : null,
            'active_coaching_plan' => $storedPlan !== [] ? $storedPlan : null,
            'last_lap_at' => now(),
        ]);

        // Option C: Trigger background Garage 61 Progressive Autopilot level-up using best clean OR optimal partial lap!
        $autopilotPace = $bestLapMs ?? $optimalLapMs;
        if ($autopilotPace !== null && $referenceLap !== null && $user->autopilot_enabled && filled($user->garage61_token)) {
            SyncGarage61AutopilotJob::dispatchAfterResponse($session->id, $autopilotPace);
        }

        return response()->json([
            'status' => 'ok',
            'lap_stored' => true,
            'lap_id' => $lap->id,
            'driving_session_id' => $session->id,
            'matched_reference_lap_id' => $referenceLap?->id,
            'optimal_lap_time_ms' => $optimalLapMs,
            'clean_laps_window' => $diagnosticResult['clean_laps_count'],
            'coaching_plan' => $diagnosticResult['plan'],
            'radio_callouts' => $radioCallouts,
            'pit_commands' => $pitCommands,
        ], 201);
    }

    /**
     * Immediate ahead-hazard radio warning (spun/stopped car 130..480m ahead in Race/Qualy).
     */
    public function hazard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'track_slug' => ['required', 'string', 'max:120'],
            'car_name' => ['nullable', 'string', 'max:160'],
            'ahead_meters' => ['required', 'integer', 'min:50', 'max:1500'],
            'lap_dist_pct' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $callouts = $this->raceStrategy->buildAheadHazardCallout($user, (int) $validated['ahead_meters']);

        return response()->json([
            'status' => 'ok',
            'radio_callouts' => $callouts,
        ]);
    }

    /**
     * Natural Push-To-Talk Voice Query & Pit-Box Action endpoint (Groq Whisper + Llama 3.3 + Deterministic Hybrid).
     */
    public function voiceQuery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'track_slug' => ['nullable', 'string', 'max:120'],
            'track_name' => ['nullable', 'string', 'max:180'],
            'track_length_km' => ['nullable', 'numeric', 'min:0.4', 'max:30'],
            'car_name' => ['nullable', 'string', 'max:160'],
            'session_id' => ['nullable', 'integer', 'min:0'],
            'sub_session_id' => ['nullable', 'integer', 'min:0'],
            'audio_wav_base64' => ['nullable', 'string', 'max:2000000'],
            'transcript' => ['nullable', 'string', 'max:500'],
            'race_context' => ['nullable', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['last_client_seen_at' => now()])->save();

        $track = ! empty($validated['track_slug'])
            ? Track::resolveOrCreateCurated(
                (string) $validated['track_slug'],
                (string) ($validated['track_name'] ?? $validated['track_slug']),
                (float) ($validated['track_length_km'] ?? 5.0)
            )
            : null;

        $session = DrivingSession::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();

        $raceCtx = is_array($validated['race_context'] ?? null) ? $validated['race_context'] : [];

        $result = $this->voiceEngineer->handleVoiceQuery(
            $user,
            $session,
            $track,
            $validated['audio_wav_base64'] ?? null,
            $validated['transcript'] ?? null,
            $raceCtx
        );

        return response()->json([
            'status' => 'ok',
            'transcript' => $result['transcript'],
            'reply_text' => $result['reply_text'],
            'radio_callouts' => $result['radio_callouts'],
            'pit_commands' => $result['pit_commands'],
        ]);
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
     * @param  array<string, int>  $bestSectors
     * @param  array<int, array{sector: int, start_pct: float, end_pct: float}>  $trackSectors
     */
    private function computeOptimalLapMs(array $bestSectors, array $trackSectors): ?int
    {
        if ($trackSectors === []) {
            return null;
        }

        $total = 0;
        foreach ($trackSectors as $sec) {
            $key = (string) $sec['sector'];
            if (! isset($bestSectors[$key]) || (int) $bestSectors[$key] <= 0) {
                return null;
            }
            $total += (int) $bestSectors[$key];
        }

        return $total > 5000 ? $total : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeByKey(array $existing, array $incoming, string $key): array
    {
        $map = [];
        foreach ($existing as $item) {
            if (isset($item[$key])) {
                $map[(int) $item[$key]] = $item;
            }
        }
        foreach ($incoming as $item) {
            if (isset($item[$key])) {
                $map[(int) $item[$key]] = $item;
            }
        }
        ksort($map);

        return array_values($map);
    }

    /**
     * Volume is owned strictly by the local Windows .exe (System Tray & pacefriend.json).
     *
     * @return array<string, never>
     */
    private function clientAudioSettings(User $user): array
    {
        return [];
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
