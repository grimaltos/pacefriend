<?php

namespace App\Services\Coaching;

use App\Models\ReferenceLap;

class ReferenceMatcherService
{
    /**
     * Select the optimal ReferenceLap matching surface conditions:
     * 1. Identical wet/dry grip regime when available.
     * 2. User-pinned (📌) references within ±8 °C take top priority (closest thermal match, then fastest).
     * 3. Otherwise, within ±3 °C of the closest available reference, prefer curated user laps
     *    over autopilot-cached laps over system seeds, breaking ties by fastest lap_time_ms.
     */
    public function selectBestReference(
        int $userId,
        int $trackId,
        string $carName,
        float $trackTempC,
        float $airTempC,
        bool $isWet
    ): ?ReferenceLap {
        $candidates = ReferenceLap::query()
            ->where('track_id', $trackId)
            ->where('car_name', $carName)
            ->where(static function ($q) use ($userId) {
                $q->where('user_id', $userId)->orWhereNull('user_id');
            })
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $sameSurface = $candidates->where('is_wet', $isWet);
        $pool = $sameSurface->isNotEmpty() ? $sameSurface : $candidates;

        $wTrack = (float) config('pacefriend.matching.track_temp_weight', 0.75);
        $wAir = (float) config('pacefriend.matching.air_temp_weight', 0.25);
        $thermalBand = (float) config('pacefriend.matching.thermal_band_c', 3.0);

        $scored = $pool->map(static function (ReferenceLap $ref) use ($trackTempC, $airTempC, $wTrack, $wAir, $userId) {
            $distance = ($wTrack * abs($trackTempC - $ref->track_temp_c))
                + ($wAir * abs($airTempC - $ref->air_temp_c));

            $tier = 0; // global seed
            if ($ref->user_id === $userId) {
                $tier = $ref->source === 'garage61_autopilot' ? 1 : 2; // curated > autopilot > global
            }

            return [
                'ref' => $ref,
                'distance' => $distance,
                'is_pinned' => (bool) $ref->is_pinned && $ref->user_id === $userId,
                'tier' => $tier,
            ];
        });

        // 1. Check user-pinned references within ±8 °C weighted thermal distance
        $pinned = $scored
            ->filter(static fn (array $item) => $item['is_pinned'] && $item['distance'] <= 8.0)
            ->sort(static function (array $a, array $b) {
                if (abs($a['distance'] - $b['distance']) > 2.0) {
                    return $a['distance'] <=> $b['distance'];
                }
                return $a['ref']->lap_time_ms <=> $b['ref']->lap_time_ms;
            });

        if ($pinned->isNotEmpty()) {
            return $pinned->first()['ref'];
        }

        // 2. Standard thermal band matching
        $minDistance = (float) $scored->min('distance');

        $withinBand = $scored
            ->filter(static fn (array $item) => $item['distance'] <= ($minDistance + $thermalBand))
            ->sort(static function (array $a, array $b) {
                if ($a['tier'] !== $b['tier']) {
                    return $b['tier'] <=> $a['tier'];
                }
                return $a['ref']->lap_time_ms <=> $b['ref']->lap_time_ms;
            });

        return $withinBand->first()['ref'] ?? null;
    }
}
