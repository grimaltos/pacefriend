<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'track_id',
    'car_name',
    'turn',
    'issue_type',
    'time_delta_ms',
    'had_incident',
])]
class CoachingOutcome extends Model
{
    protected function casts(): array
    {
        return [
            'track_id' => 'integer',
            'turn' => 'integer',
            'time_delta_ms' => 'integer',
            'had_incident' => 'boolean',
        ];
    }
}
