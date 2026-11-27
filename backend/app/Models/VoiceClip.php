<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'hash',
    'provider',
    'voice_id',
    'text',
    'disk_path',
    'mime_type',
    'duration_ms',
])]
class VoiceClip extends Model
{
    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
        ];
    }
}
