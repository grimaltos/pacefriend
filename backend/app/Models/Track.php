<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'garage61_track_id', 'name', 'length_km', 'corners'])]
class Track extends Model
{
    protected function casts(): array
    {
        return [
            'garage61_track_id' => 'integer',
            'length_km' => 'float',
            'corners' => 'array',
        ];
    }

    public static function resolveOrCreateCurated(string $slug, string $name = '', float $lengthKm = 5.0): self
    {
        $normalizedSlug = \Illuminate\Support\Str::slug($slug) ?: 'unknown-track';
        $existing = static::query()->where('slug', $normalizedSlug)->first();
        if ($existing !== null) {
            return $existing;
        }

        // Check curated tactical catalog by slug or fuzzy circuit name
        $catalogPath = database_path('data/tracks_catalog.json');
        $catalog = is_file($catalogPath) ? (json_decode((string) file_get_contents($catalogPath), true) ?: []) : [];
        $needle = strtolower($name !== '' ? $name : $normalizedSlug);

        foreach ($catalog as $entry) {
            if ($entry['slug'] === $normalizedSlug || str_contains(strtolower($entry['name']), $needle) || str_contains($needle, strtolower(explode(' - ', $entry['name'])[0]))) {
                return static::query()->firstOrCreate(
                    ['slug' => $entry['slug']],
                    [
                        'name' => $entry['name'],
                        'length_km' => $entry['length_km'],
                        'corners' => $entry['corners'],
                    ]
                );
            }
        }

        return static::query()->create([
            'slug' => $normalizedSlug,
            'name' => $name !== '' ? $name : ucwords(str_replace('-', ' ', $normalizedSlug)),
            'length_km' => $lengthKm > 0 ? $lengthKm : 5.0,
            'corners' => [
                ['turn' => 1, 'name' => 'Curva 1', 'brake_pct' => 0.120, 'apex_pct' => 0.150, 'exit_pct' => 0.180],
                ['turn' => 2, 'name' => 'Curva 2', 'brake_pct' => 0.370, 'apex_pct' => 0.400, 'exit_pct' => 0.430],
                ['turn' => 3, 'name' => 'Curva 3', 'brake_pct' => 0.620, 'apex_pct' => 0.650, 'exit_pct' => 0.680],
                ['turn' => 4, 'name' => 'Curva 4', 'brake_pct' => 0.860, 'apex_pct' => 0.890, 'exit_pct' => 0.920],
            ],
        ]);
    }

    public function referenceLaps(): HasMany
    {
        return $this->hasMany(ReferenceLap::class);
    }

    public function drivingSessions(): HasMany
    {
        return $this->hasMany(DrivingSession::class);
    }

    public function laps(): HasMany
    {
        return $this->hasMany(Lap::class);
    }
}
