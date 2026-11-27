<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'track_id',
    'car_name',
    'label',
    'lap_time_ms',
    'track_temp_c',
    'air_temp_c',
    'is_wet',
    'fuel_level_l',
    'fuel_used_l',
    'is_pinned',
    'track_usage',
    'source',
    'external_id',
    'driver_name',
    'normalized_points',
    'corner_baselines',
    'sector_splits_ms',
])]
class ReferenceLap extends Model
{
    protected function casts(): array
    {
        return [
            'lap_time_ms' => 'integer',
            'track_temp_c' => 'float',
            'air_temp_c' => 'float',
            'is_wet' => 'boolean',
            'fuel_level_l' => 'float',
            'fuel_used_l' => 'float',
            'is_pinned' => 'boolean',
            'normalized_points' => 'array',
            'corner_baselines' => 'array',
            'sector_splits_ms' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
