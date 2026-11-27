<?php

namespace App\Services\Coaching;

use App\Models\ReferenceLap;

class ReferenceMatcherService
{
    /**
     * Select the optimal ReferenceLap whose track conditions (wet/dry, asphalt temp, air temp)
     * most closely match the current session while offering the fastest benchmark within the thermal band.
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

        // Prioritize identical wet/dry grip regime when available.
        $sameSurface = $candidates->where('is_wet', $isWet);
        $pool = $sameSurface->isNotEmpty() ? $sameSurface : $candidates;

        $wTrack = (float) config('pacefriend.matching.track_temp_weight', 0.75);
        $wAir = (float) config('pacefriend.matching.air_temp_weight', 0.25);
        $thermalBand = (float) config('pacefriend.matching.thermal_band_c', 3.0);

        $scored = $pool->map(static function (ReferenceLap $ref) use ($trackTempC, $airTempC, $wTrack, $wAir, $userId) {
            $distance = ($wTrack * abs($trackTempC - $ref->track_temp_c))
                + ($wAir * abs($airTempC - $ref->air_temp_c));

            return [
                'ref' => $ref,
                'distance' => $distance,
                'is_owned' => $ref->user_id === $userId,
            ];
        });

        $minDistance = (float) $scored->min('distance');

        // Within a narrow thermal band (±3 °C weighted) grip is comparable; pick the fastest reference
        // (preferring user's own uploaded reference laps on close ties).
        $withinBand = $scored
            ->filter(static fn (array $item) => $item['distance'] <= ($minDistance + $thermalBand))
            ->sort(static function (array $a, array $b) {
                if ($a['is_owned'] !== $b['is_owned']) {
                    return $b['is_owned'] <=> $a['is_owned'];
                }
                return $a['ref']->lap_time_ms <=> $b['ref']->lap_time_ms;
            });

        return $withinBand->first()['ref'] ?? null;
    }
}
