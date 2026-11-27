<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'driving_session_id',
    'user_id',
    'reference_lap_id',
    'status',
    'llm_driver',
    'quantitative_summary',
    'priority_areas',
    'narrative_markdown',
    'generated_at',
])]
class SessionReport extends Model
{
    protected function casts(): array
    {
        return [
            'quantitative_summary' => 'array',
            'priority_areas' => 'array',
            'generated_at' => 'datetime',
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

    public function referenceLap(): BelongsTo
    {
        return $this->belongsTo(ReferenceLap::class);
    }
}
