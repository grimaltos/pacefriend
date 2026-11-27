<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'track_id',
    'active_reference_lap_id',
    'car_name',
    'iracing_session_id',
    'iracing_subsession_id',
    'avg_track_temp_c',
    'avg_air_temp_c',
    'is_wet',
    'laps_count',
    'clean_laps_count',
    'clean_sectors_count',
    'best_lap_time_ms',
    'optimal_lap_time_ms',
    'best_sectors_ms',
    'active_coaching_plan',
    'started_at',
    'last_lap_at',
    'ended_at',
])]
class DrivingSession extends Model
{
    protected function casts(): array
    {
        return [
            'avg_track_temp_c' => 'float',
            'avg_air_temp_c' => 'float',
            'is_wet' => 'boolean',
            'laps_count' => 'integer',
            'clean_laps_count' => 'integer',
            'clean_sectors_count' => 'integer',
            'best_lap_time_ms' => 'integer',
            'optimal_lap_time_ms' => 'integer',
            'best_sectors_ms' => 'array',
            'active_coaching_plan' => 'array',
            'started_at' => 'datetime',
            'last_lap_at' => 'datetime',
            'ended_at' => 'datetime',
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

    public function activeReferenceLap(): BelongsTo
    {
        return $this->belongsTo(ReferenceLap::class, 'active_reference_lap_id');
    }

    public function laps(): HasMany
    {
        return $this->hasMany(Lap::class)->orderBy('lap_number');
    }

    public function report(): HasOne
    {
        return $this->hasOne(SessionReport::class);
    }
}
