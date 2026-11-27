<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'garage61_track_id', 'name', 'length_km', 'corners', 'sectors'])]
class Track extends Model
{
    protected function casts(): array
    {
        return [
            'garage61_track_id' => 'integer',
            'length_km' => 'float',
            'corners' => 'array',
            'sectors' => 'array',
        ];
    }

    /**
     * Return official iRacing sector boundaries [{sector, start_pct, end_pct}, ...],
     * falling back to 3 balanced partials if not yet populated from SessionInfo YAML.
     *
     * @return array<int, array{sector: int, start_pct: float, end_pct: float}>
     */
    public function effectiveSectors(): array
    {
        if (is_array($this->sectors) && count($this->sectors) >= 2) {
            return array_values($this->sectors);
        }

        return [
            ['sector' => 1, 'start_pct' => 0.0000, 'end_pct' => 0.3333],
            ['sector' => 2, 'start_pct' => 0.3333, 'end_pct' => 0.6667],
            ['sector' => 3, 'start_pct' => 0.6667, 'end_pct' => 1.0000],
        ];
    }

    /**
     * Update track sector boundaries from an ordered array of iRacing SectorStartPct floats.
     *
     * @param  array<int, float|int>  $startPcts
     */
    public function syncSectorSplits(array $startPcts): void
    {
        $clean = [];
        foreach ($startPcts as $p) {
            $val = round(max(0.0, min(0.999, (float) $p)), 4);
            $clean[] = $val;
        }
        $clean = array_values(array_unique($clean));
        sort($clean);

        if (count($clean) < 2) {
            return;
        }
        if ($clean[0] > 0.02) {
            array_unshift($clean, 0.0);
        } else {
            $clean[0] = 0.0;
        }

        $sectors = [];
        $n = count($clean);
        for ($i = 0; $i < $n; $i++) {
            $sectors[] = [
                'sector' => $i + 1,
                'start_pct' => $clean[$i],
                'end_pct' => ($i + 1 < $n) ? $clean[$i + 1] : 1.0000,
            ];
        }

        if ($this->sectors !== $sectors) {
            $this->update(['sectors' => $sectors]);
        }
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
