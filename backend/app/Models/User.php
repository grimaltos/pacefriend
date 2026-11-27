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

    /**
     * Normalized radio engineer voice, FX, and on-track callout preferences.
     *
     * @return array{
     *   voice_id: string,
     *   volume: int,
     *   radio_beep: bool,
     *   pit_wall_fx: bool,
     *   finish_callout_mode: string,
     *   min_clean_laps: int,
     *   fuel_alerts: bool,
     *   tyre_temp_alerts: bool
     * }
     */
    public function radioSettings(): array
    {
        $prefs = $this->preferences ?? [];
        $allowedVoices = array_keys(config('pacefriend.voice.edge.voices', [
            'es-ES-AlvaroNeural' => 'Álvaro',
            'es-ES-XimenaNeural' => 'Ximena',
            'es-ES-ElviraNeural' => 'Elvira',
        ]));

        $voice = (string) ($prefs['radio_voice_id'] ?? config('pacefriend.voice.edge.default_voice', 'es-ES-AlvaroNeural'));
        if (! in_array($voice, $allowedVoices, true)) {
            $voice = 'es-ES-AlvaroNeural';
        }

        $finishMode = (string) ($prefs['finish_callout_mode'] ?? 'full');
        if (! in_array($finishMode, ['full', 'improvements', 'off'], true)) {
            $finishMode = 'full';
        }

        return [
            'voice_id' => $voice,
            'volume' => max(0, min(100, (int) ($prefs['radio_volume'] ?? 85))),
            'radio_beep' => (bool) ($prefs['radio_beep'] ?? true),
            'pit_wall_fx' => (bool) ($prefs['pit_wall_fx'] ?? true),
            'finish_callout_mode' => $finishMode,
            'min_clean_laps' => max(2, min(5, (int) ($prefs['min_clean_laps'] ?? config('pacefriend.min_chronic_occurrences', 3)))),
            'fuel_alerts' => (bool) ($prefs['fuel_alerts'] ?? true),
            'tyre_temp_alerts' => (bool) ($prefs['tyre_temp_alerts'] ?? true),
        ];
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
