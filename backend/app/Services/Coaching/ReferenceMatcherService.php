<?php

namespace App\Services\Coaching;

use App\Models\ReferenceLap;

class ReferenceMatcherService
{
    /**
     * Select the optimal ReferenceLap matching surface conditions, returning null if
     * no stored reference is physically comparable (surface mismatch or > ±7 °C asphalt delta).
     */
    public function selectBestReference(
        int $userId,
        int $trackId,
        string $carName,
        float $trackTempC,
        float $airTempC,
        bool $isWet,
        ?int $driverBestLapMs = null
    ): ?ReferenceLap {
        return $this->matchWithDiagnostics($userId, $trackId, $carName, $trackTempC, $airTempC, $isWet, $driverBestLapMs)['reference'];
    }

    /**
     * Match a physically comparable ReferenceLap or explain why stored references were discarded
     * so the engineer can transparently switch to Session Self-Reference mode.
     *
     * @return array{
     *   reference: ReferenceLap|null,
     *   discarded_reason: string|null,
     *   discarded_ref: ReferenceLap|null
     * }
     */
    public function matchWithDiagnostics(
        int $userId,
        int $trackId,
        string $carName,
        float $trackTempC,
        float $airTempC,
        bool $isWet,
        ?int $driverBestLapMs = null
    ): array {
        $candidates = ReferenceLap::query()
            ->where('track_id', $trackId)
            ->where('car_name', $carName)
            ->where(static function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'reference' => null,
                'discarded_reason' => 'none',
                'discarded_ref' => null,
            ];
        }

        // Strict surface rule: never compare dry vs wet telemetry
        $sameSurface = $candidates->where('is_wet', $isWet)->values();
        if ($sameSurface->isEmpty()) {
            return [
                'reference' => null,
                'discarded_reason' => 'surface_mismatch',
                'discarded_ref' => $candidates->first(),
            ];
        }

        $maxTempDelta = (float) config('pacefriend.matching.max_comparable_temp_delta_c', 7.0);
        $wTrack = (float) config('pacefriend.matching.track_temp_weight', 0.75);
        $wAir = (float) config('pacefriend.matching.air_temp_weight', 0.25);
        $thermalBand = (float) config('pacefriend.matching.thermal_band_c', 3.0);

        $scored = $sameSurface->map(static function (ReferenceLap $ref) use ($trackTempC, $airTempC, $wTrack, $wAir, $userId) {
            $rawTrackDelta = abs($trackTempC - $ref->track_temp_c);
            $distance = ($wTrack * $rawTrackDelta) + ($wAir * abs($airTempC - $ref->air_temp_c));

            $tier = 0; // baseline pool (seed, pacefriend_community, personal_auto) -> fastest lap_time_ms wins!
            if ($ref->user_id === $userId) {
                if ($ref->source === 'garage61_autopilot') {
                    $tier = 1;
                } elseif (! in_array($ref->source, ['personal_auto', 'pacefriend_community', 'seed'], true)) {
                    $tier = 2; // explicit user-imported references (.ibt, G61 manual/CSV, promoted lap)
                }
            }

            return [
                'ref' => $ref,
                'raw_track_delta' => $rawTrackDelta,
                'distance' => $distance,
                'is_pinned' => (bool) $ref->is_pinned && $ref->user_id === $userId,
                'tier' => $tier,
            ];
        });

        // Enforce strict thermal comparability ceiling (±7.0 °C asphalt temperature)
        $comparable = $scored->filter(static fn (array $item) => $item['raw_track_delta'] <= $maxTempDelta);
        if ($comparable->isEmpty()) {
            $closest = $scored->sortBy('raw_track_delta')->first();
            return [
                'reference' => null,
                'discarded_reason' => 'temp_mismatch',
                'discarded_ref' => $closest['ref'] ?? null,
            ];
        }

        // 1. User-pinned references within comparable thermal window take top priority
        $pinned = $comparable
            ->filter(static fn (array $item) => $item['is_pinned'])
            ->sort(static function (array $a, array $b) {
                if (abs($a['distance'] - $b['distance']) > 2.0) {
                    return $a['distance'] <=> $b['distance'];
                }
                return $a['ref']->lap_time_ms <=> $b['ref']->lap_time_ms;
            });

        if ($pinned->isNotEmpty()) {
            return [
                'reference' => $pinned->first()['ref'],
                'discarded_reason' => null,
                'discarded_ref' => null,
            ];
        }

        // Auto-harvested references (community pool & personal auto-PBs) are only used as external targets
        // when they are meaningfully faster (> 150ms) than the driver's current session best; otherwise
        // PaceFriend switches to Session Self-Reference + Friction Circle so even the fastest driver keeps improving!
        if ($driverBestLapMs !== null && $driverBestLapMs > 0) {
            $comparable = $comparable->filter(static function (array $item) use ($driverBestLapMs) {
                if (in_array($item['ref']->source, ['pacefriend_community', 'personal_auto'], true)) {
                    return $item['ref']->lap_time_ms < ($driverBestLapMs - 150);
                }

                return true;
            });

            if ($comparable->isEmpty()) {
                return [
                    'reference' => null,
                    'discarded_reason' => 'auto_self_pace',
                    'discarded_ref' => null,
                ];
            }
        }

        // 2. Standard thermal band matching within comparable candidates
        $minDistance = (float) $comparable->min('distance');
        $withinBand = $comparable
            ->filter(static fn (array $item) => $item['distance'] <= ($minDistance + $thermalBand))
            ->sort(static function (array $a, array $b) {
                if ($a['tier'] !== $b['tier']) {
                    return $b['tier'] <=> $a['tier'];
                }
                return $a['ref']->lap_time_ms <=> $b['ref']->lap_time_ms;
            });

        return [
            'reference' => $withinBand->first()['ref'] ?? null,
            'discarded_reason' => null,
            'discarded_ref' => null,
        ];
    }
}
