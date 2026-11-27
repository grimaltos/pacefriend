<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'driver_callsign',
    'callsign_phonetic',
    'email',
    'password',
    'preferences',
    'garage61_token',
    'garage61_auth_type',
    'garage61_refresh_token',
    'garage61_token_expires_at',
    'autopilot_enabled',
    'reference_scope',
    'autopilot_mode',
    'autopilot_delta_ms',
    'last_client_seen_at',
])]
#[Hidden(['password', 'remember_token', 'garage61_token', 'garage61_refresh_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_client_seen_at' => 'datetime',
            'garage61_token_expires_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'garage61_token' => 'encrypted',
            'garage61_refresh_token' => 'encrypted',
            'autopilot_enabled' => 'boolean',
            'autopilot_delta_ms' => 'integer',
        ];
    }

    /**
     * Display callsign used in written PRO reports and UI.
     */
    public function effectiveCallsign(): string
    {
        $callsign = trim((string) $this->driver_callsign);
        if ($callsign !== '') {
            return $callsign;
        }

        $first = trim(Str::before((string) $this->name, ' '));
        return $first !== '' ? $first : 'Piloto';
    }

    /**
     * Phonetic-aware callsign sent to neural TTS engines so nicknames pronounce naturally.
     */
    public function spokenCallsign(): string
    {
        $phonetic = trim((string) $this->callsign_phonetic);
        return $phonetic !== '' ? $phonetic : $this->effectiveCallsign();
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

    public function sessionReports(): HasMany
    {
        return $this->hasMany(SessionReport::class);
    }
}
