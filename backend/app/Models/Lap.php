<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'driving_session_id',
    'user_id',
    'track_id',
    'reference_lap_id',
    'car_name',
    'lap_number',
    'lap_time_ms',
    'is_clean',
    'incident_delta',
    'track_temp_c',
    'air_temp_c',
    'is_wet',
    'sample_rate_hz',
    'samples',
    'corner_metrics',
])]
class Lap extends Model
{
    protected function casts(): array
    {
        return [
            'lap_number' => 'integer',
            'lap_time_ms' => 'integer',
            'is_clean' => 'boolean',
            'incident_delta' => 'integer',
            'track_temp_c' => 'float',
            'air_temp_c' => 'float',
            'is_wet' => 'boolean',
            'sample_rate_hz' => 'integer',
            'samples' => 'array',
            'corner_metrics' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(DrivingSession::class, 'driving_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function referenceLap(): BelongsTo
    {
        return $this->belongsTo(ReferenceLap::class);
    }
}
