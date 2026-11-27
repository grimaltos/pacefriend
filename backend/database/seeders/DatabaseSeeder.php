<?php

namespace Database\Seeders;

use App\Models\ReferenceLap;
use App\Models\Track;
use App\Services\Telemetry\TelemetryNormalizerService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $normalizer = app(TelemetryNormalizerService::class);

        $catalogPath = database_path('data/tracks_catalog.json');
        $tracks = is_file($catalogPath)
            ? (json_decode((string) file_get_contents($catalogPath), true) ?: [])
            : [];

        $spa = null;
        foreach ($tracks as $data) {
            $t = Track::query()->updateOrCreate(['slug' => $data['slug']], $data);
            if ($t->slug === 'spa-up') {
                $spa = $t;
            }
        }

        if ($spa !== null && ReferenceLap::query()->whereNull('user_id')->count() === 0) {
            foreach ([
                ['label' => 'Referencia Base Fría (21 °C)', 'track_temp_c' => 21.0, 'air_temp_c' => 17.0, 'lap_time_ms' => 136800, 'brake_shift' => 0.002],
                ['label' => 'Referencia Base Calurosa (33 °C)', 'track_temp_c' => 33.0, 'air_temp_c' => 24.0, 'lap_time_ms' => 138100, 'brake_shift' => 0.000],
            ] as $preset) {
                $samples = $this->generateSyntheticSpaReference($preset['lap_time_ms'], $preset['brake_shift']);
                $normalized = $normalizer->normalizeSamples($samples);
                $baselines = $normalizer->extractCornerBaselines($samples, $spa->corners, $preset['lap_time_ms']);

                ReferenceLap::query()->create([
                    'user_id' => null,
                    'track_id' => $spa->id,
                    'car_name' => 'Porsche 911 GT3 R (992)',
                    'label' => $preset['label'],
                    'lap_time_ms' => $preset['lap_time_ms'],
                    'track_temp_c' => $preset['track_temp_c'],
                    'air_temp_c' => $preset['air_temp_c'],
                    'is_wet' => false,
                    'source' => 'system_seed',
                    'normalized_points' => $normalized,
                    'corner_baselines' => $baselines,
                ]);
            }
        }
    }

    /**
     * @return array<int, array<string, float|int>>
     */
    private function generateSyntheticSpaReference(int $lapTimeMs, float $brakeShift): array
    {
        $samples = [];
        $steps = 250;
        $elapsedMs = 0.0;
        $stepMeters = 7004.0 / $steps;

        for ($i = 0; $i < $steps; $i++) {
            $pct = round($i / $steps, 4);
            $speed = 74.0;
            $throttle = 1.0;
            $brake = 0.0;
            $gear = 6;

            // La Source optimal late braking at 0.980 + brakeShift
            if ($pct >= (0.980 + $brakeShift) || $pct <= 0.030) {
                if ($pct >= 0.980) {
                    $speed = 32.0;
                    $brake = 0.95;
                    $throttle = 0.0;
                    $gear = 2;
                } elseif ($pct <= 0.013) {
                    $speed = 21.5;
                    $brake = 0.10;
                    $throttle = 0.20;
                    $gear = 1;
                } else {
                    $speed = 46.0;
                    $brake = 0.0;
                    $throttle = 0.96;
                    $gear = 2;
                }
            } elseif ($pct >= 0.278 && $pct <= 0.326) {
                $speed = $pct < 0.296 ? 38.0 : 52.0;
                $brake = $pct < 0.294 ? 0.85 : 0.0;
                $throttle = $pct >= 0.297 ? 0.95 : 0.0;
                $gear = 3;
            } elseif ($pct >= 0.428 && $pct <= 0.474) {
                $speed = $pct < 0.449 ? 29.0 : 48.0;
                $brake = $pct < 0.446 ? 0.80 : 0.0;
                $throttle = $pct >= 0.451 ? 0.95 : 0.0;
                $gear = 2;
            } elseif ($pct >= 0.898 && $pct <= 0.948) {
                $speed = $pct < 0.921 ? 23.0 : 45.0;
                $brake = $pct < 0.918 ? 0.90 : 0.0;
                $throttle = $pct >= 0.924 ? 0.95 : 0.0;
                $gear = 2;
            }

            $samples[] = [
                't_ms' => (int) round($elapsedMs),
                'pct' => $pct,
                'speed' => $speed,
                'throttle' => $throttle,
                'brake' => $brake,
                'lap' => 1,
                'gear' => $gear,
                'flags' => 0,
            ];
            $elapsedMs += ($stepMeters / $speed) * 1000.0;
        }

        // Scale timestamps proportionally to exact target lapTimeMs
        $scale = $elapsedMs > 0 ? ($lapTimeMs / $elapsedMs) : 1.0;
        foreach ($samples as &$s) {
            $s['t_ms'] = (int) round($s['t_ms'] * $scale);
        }

        return $samples;
    }
}
