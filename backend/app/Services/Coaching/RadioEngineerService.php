<?php

namespace App\Services\Coaching;

use App\Models\DrivingSession;
use App\Models\Lap;
use App\Models\ReferenceLap;
use App\Models\Track;
use App\Models\User;
use App\Services\Voice\VoiceSynthesisManager;
use Illuminate\Support\Str;

class RadioEngineerService
{
    public function __construct(
        private readonly VoiceSynthesisManager $voiceManager,
    ) {}

    /**
     * Build the initial out-lap / session greeting or weather-shift alert.
     *
     * @return array<int, array{id: string, text: string, audio_url: string, audio_sha256: string}>
     */
    public function buildBriefing(
        User $user,
        Track $track,
        float $trackTempC,
        float $airTempC,
        bool $isWet,
        ?float $fuelLevelL,
        ?ReferenceLap $referenceLap,
        string $reason = 'session_start'
    ): array {
        $radio = $user->radioSettings();
        $callsign = $user->spokenCallsign();
        $surface = $isWet ? 'mojada' : 'seca';
        $tTrack = (int) round($trackTempC);
        $tAir = (int) round($airTempC);

        if ($reason === 'conditions_changed') {
            $parts = [
                "Atento {$callsign}, cambian las condiciones en pista: ahora {$surface} a {$tTrack} grados.",
            ];
            if ($referenceLap !== null) {
                $parts[] = "Ajustamos referencia a {$this->spokenLapTime($referenceLap->lap_time_ms)}.";
            }

            return [$this->makeCallout('briefing_weather_'.substr(md5(implode(' ', $parts)), 0, 8), implode(' ', $parts), $radio)];
        }

        $shortTrack = $this->shortTrackName($track->name);
        $parts = [
            "Buenas {$callsign}, radio conectada en {$shortTrack}.",
            "Pista {$surface} a {$tTrack} grados, ambiente {$tAir}.",
        ];

        if ($fuelLevelL !== null && $fuelLevelL > 0.5) {
            $liters = (int) round($fuelLevelL);
            $parts[] = "Sales con {$liters} litros.";
        }

        if ($referenceLap !== null) {
            $refTime = $this->spokenLapTime($referenceLap->lap_time_ms);
            $driver = $this->shortDriverName($referenceLap->driver_name);
            $parts[] = $driver !== ''
                ? "Tu referencia es {$refTime} de {$driver}."
                : "Tu referencia objetivo es {$refTime}.";

            if ($radio['fuel_alerts'] && $fuelLevelL !== null && $fuelLevelL > 0.5 && $referenceLap->fuel_level_l !== null && $referenceLap->fuel_level_l > 0.5) {
                $diffL = (int) round($fuelLevelL - $referenceLap->fuel_level_l);
                if ($diffL >= 10) {
                    $tenths = max(2, (int) round(($diffL / 10.0) * 1.8));
                    $parts[] = "Llevas {$diffL} litros más que el target, cuenta con unas {$tenths} décimas de peso.";
                } elseif ($diffL <= -10) {
                    $lighter = abs($diffL);
                    $parts[] = "Vas {$lighter} litros más ligero que la referencia.";
                }
            }
        } elseif ($user->autopilot_enabled && filled($user->garage61_token)) {
            $parts[] = 'Completa una vuelta limpia y te cargo objetivo automático de Garage 61.';
        }

        if ($radio['tyre_temp_alerts']) {
            $parts[] = $trackTempC < 23.0
                ? 'Asfalto frío, calienta bien gomas y frenos en esta vuelta.'
                : 'Cuidado con gomas frías en las primeras curvas, vamos de menos a más.';
        }

        $text = implode(' ', $parts);

        return [$this->makeCallout('briefing_start_'.substr(md5($text), 0, 8), $text, $radio)];
    }

    /**
     * Build mid-lap partial/sector crossing callout (e.g. S1, S2) respecting sector_callout_mode.
     *
     * @return array<int, array{id: string, text: string, audio_url: string, audio_sha256: string}>
     */
    public function buildSectorCrossingCallouts(
        User $user,
        int $lapNumber,
        int $sectorNumber,
        int $sectorTimeMs,
        bool $isClean,
        ?int $previousBestSectorMs,
        ?int $targetSectorMs,
        bool $newAutopilotRef = false,
        ?ReferenceLap $currentRef = null
    ): array {
        $radio = $user->radioSettings();
        $mode = $radio['sector_callout_mode'] ?? 'improvements';
        $callsign = $user->spokenCallsign();
        $sectorLabel = $this->spokenSectorLabel($sectorNumber);
        $sentences = [];

        $beatTarget = $isClean && $targetSectorMs !== null && $sectorTimeMs <= $targetSectorMs;
        $isPurple = $isClean && $previousBestSectorMs !== null && $sectorTimeMs < ($previousBestSectorMs - 60);
        $matchedTarget = $isClean && $targetSectorMs !== null && ($sectorTimeMs - $targetSectorMs) <= 80;

        if ($mode === 'full' || ($mode === 'improvements' && ($beatTarget || $isPurple || $matchedTarget))) {
            if (! $isClean) {
                $uc = ucfirst($sectorLabel);
                $sentences[] = "{$uc} sucio, mantenemos foco en el resto de la vuelta.";
            } elseif ($beatTarget) {
                $sentences[] = "¡Brutal {$sectorLabel} {$callsign}! Más rápido que el target.";
            } elseif ($isPurple && $previousBestSectorMs !== null) {
                $gain = $this->spokenDelta($previousBestSectorMs - $sectorTimeMs);
                $sentences[] = "¡Récord personal en el {$sectorLabel}! Bajas {$gain}.";
            } elseif ($matchedTarget) {
                $sentences[] = "¡Muy buen {$sectorLabel}, calcando a la referencia!";
            } elseif ($targetSectorMs !== null) {
                $uc = ucfirst($sectorLabel);
                $gap = max(0, $sectorTimeMs - $targetSectorMs);
                $sentences[] = "{$uc} limpio, a {$this->spokenDelta($gap)} del objetivo.";
            } else {
                $uc = ucfirst($sectorLabel);
                $sentences[] = "{$uc} limpio, {$this->spokenLapTime($sectorTimeMs)}.";
            }
        }

        if ($newAutopilotRef && $currentRef !== null) {
            $sentences[] = "Objetivo automático fijado en {$this->spokenLapTime($currentRef->lap_time_ms)}.";
        }

        if ($sentences === []) {
            return [];
        }

        $text = implode(' ', $sentences);
        return [$this->makeCallout("lap_{$lapNumber}_s{$sectorNumber}_".substr(md5($text), 0, 6), $text, $radio)];
    }

    /**
     * Build start/finish line lap time callout, positive reinforcement, Autopilot update, and fuel alerts.
     *
     * @param  array<string, mixed>|null  $previousCoachingPlan
     * @param  array<string, bool>  $radioFlags
     * @return array<int, array{id: string, text: string, audio_url: string, audio_sha256: string}>
     */
    public function buildLapCrossingCallouts(
        User $user,
        DrivingSession $session,
        Lap $lap,
        ?int $previousBestMs,
        ?array $previousCoachingPlan,
        ?ReferenceLap $previousRef,
        ?ReferenceLap $currentRef,
        ?float $avgFuelUsedL,
        array &$radioFlags
    ): array {
        $radio = $user->radioSettings();
        $callsign = $user->spokenCallsign();
        $callouts = [];

        $shortForTurn1 = $this->isTurnOneCloseToStartLine($lap->track);
        $beatTarget = $lap->is_clean && $currentRef !== null && $lap->lap_time_ms <= $currentRef->lap_time_ms;
        $isNewSessionPb = $lap->is_clean && $previousBestMs !== null && $lap->lap_time_ms < ($previousBestMs - 80);
        $improvedCorner = $lap->is_clean ? $this->detectFocusCornerImprovement($lap, $previousCoachingPlan) : null;
        $consistentPace = $lap->is_clean && $previousBestMs !== null && abs($lap->lap_time_ms - $previousBestMs) <= 180;
        $newAutopilotRef = $currentRef !== null && ($previousRef === null || $previousRef->id !== $currentRef->id);

        $mode = $radio['finish_callout_mode'];
        $shouldSpeakLap = $mode === 'full'
            || ($mode === 'improvements' && ($beatTarget || $isNewSessionPb || $improvedCorner !== null || $newAutopilotRef));

        if ($shouldSpeakLap) {
            $spokenTime = $this->spokenLapTime($lap->lap_time_ms);
            $sentences = [];

            if (! $lap->is_clean) {
                $cleanSectorCount = count(array_filter(
                    $lap->sector_metrics ?? [],
                    static fn (array $sm) => ! empty($sm['is_clean'])
                ));
                if (! $shortForTurn1 && $improvedCorner !== null) {
                    $sentences[] = "Vuelta con límites, pero ¡muy bien {$improvedCorner['name']}, recortas {$this->spokenDelta($improvedCorner['gain_ms'])} y aprovechamos tus parciales limpios!";
                } elseif (! $shortForTurn1 && $cleanSectorCount > 0) {
                    $sentences[] = "Vuelta con límites, {$spokenTime}. Guardamos tus {$cleanSectorCount} parciales limpios para el análisis.";
                } else {
                    $sentences[] = $shortForTurn1
                        ? "Vuelta sucia, {$spokenTime}."
                        : "Vuelta con límites, {$spokenTime}. Cabeza fría para esta.";
                }
            } elseif ($beatTarget) {
                $sentences[] = "¡Vueltón {$callsign}! {$spokenTime}, acabas de batir el objetivo.";
            } elseif ($isNewSessionPb && $previousBestMs !== null) {
                $gain = $this->spokenDelta($previousBestMs - $lap->lap_time_ms);
                $sentences[] = "¡Buena vuelta {$callsign}! {$spokenTime}, bajas {$gain}.";
                if (! $shortForTurn1 && $improvedCorner !== null) {
                    $sentences[] = "¡Mucho mejor {$improvedCorner['name']}, ganas {$this->spokenDelta($improvedCorner['gain_ms'])} ahí!";
                }
            } elseif ($improvedCorner !== null) {
                $sentences[] = "{$spokenTime}. ¡Muy bien en {$improvedCorner['name']}, recortas {$this->spokenDelta($improvedCorner['gain_ms'])}!";
            } elseif ($consistentPace) {
                if ($currentRef !== null && ! $shortForTurn1) {
                    $gap = max(0, $lap->lap_time_ms - $currentRef->lap_time_ms);
                    $sentences[] = "{$spokenTime}, ritmo muy sólido a {$this->spokenDelta($gap)} del objetivo.";
                } else {
                    $sentences[] = "{$spokenTime}, calcando tu ritmo.";
                }
            } else {
                if ($currentRef !== null && ! $shortForTurn1) {
                    $gap = max(0, $lap->lap_time_ms - $currentRef->lap_time_ms);
                    $sentences[] = "{$spokenTime}, a {$this->spokenDelta($gap)} de la referencia.";
                } else {
                    $sentences[] = "Vuelta limpia, {$spokenTime}.";
                }
            }

            if ($newAutopilotRef && $currentRef !== null && ! $beatTarget) {
                $sentences[] = "Nuevo objetivo fijado en {$this->spokenLapTime($currentRef->lap_time_ms)}.";
            }

            $lapSpeech = implode(' ', $sentences);
            if ($lapSpeech !== '') {
                $callouts[] = $this->makeCallout("lap_{$lap->lap_number}_summary", $lapSpeech, $radio);
            }
        }

        // Fuel reserve & target consumption comparison
        if ($radio['fuel_alerts'] && $lap->fuel_level_l !== null && $lap->fuel_level_l > 0.1 && $avgFuelUsedL !== null && $avgFuelUsedL > 0.25) {
            $lapsRemaining = $lap->fuel_level_l / $avgFuelUsedL;

            if ($lapsRemaining <= 1.35 && empty($radioFlags['fuel_1_warned'])) {
                $radioFlags['fuel_1_warned'] = true;
                $litersTxt = str_replace('.', ' coma ', (string) round($lap->fuel_level_l, 1));
                $callouts[] = $this->makeCallout(
                    "fuel_critical_lap_{$lap->lap_number}",
                    "¡Box esta vuelta {$callsign}! Combustible justo para una vuelta, quedan {$litersTxt} litros.",
                    $radio
                );
            } elseif ($lapsRemaining <= 3.25 && $lapsRemaining > 1.35 && empty($radioFlags['fuel_3_warned'])) {
                $radioFlags['fuel_3_warned'] = true;
                $callouts[] = $this->makeCallout(
                    "fuel_low_lap_{$lap->lap_number}",
                    "Atento al depósito {$callsign}, tienes gasolina para unas tres vueltas.",
                    $radio
                );
            } elseif (
                $lap->is_clean
                && $session->clean_laps_count >= 3
                && empty($radioFlags['fuel_burn_warned'])
                && $currentRef !== null
                && $currentRef->fuel_used_l !== null
                && $currentRef->fuel_used_l > 0.3
            ) {
                $excessL = $avgFuelUsedL - $currentRef->fuel_used_l;
                if ($excessL >= 0.20 && ($excessL / $currentRef->fuel_used_l) >= 0.08) {
                    $radioFlags['fuel_burn_warned'] = true;
                    $excessTxt = str_replace('.', ' coma ', (string) round($excessL, 1));
                    $callouts[] = $this->makeCallout(
                        "fuel_burn_lap_{$lap->lap_number}",
                        "Nota de consumo: gastas {$excessTxt} litros más por vuelta que la referencia.",
                        $radio
                    );
                }
            }
        }

        return $callouts;
    }

    /**
     * Build real tyre pyrometer debrief when stopping in pits or exiting to the garage.
     *
     * @param  array{lf: float, rf: float, lr: float, rr: float}  $tyresC
     * @return array<int, array{id: string, text: string, audio_url: string, audio_sha256: string}>
     */
    public function buildPitTyreDebrief(User $user, array $tyresC, int $lapsCompleted): array
    {
        $radio = $user->radioSettings();
        if (! $radio['tyre_temp_alerts']) {
            return [];
        }

        $valid = array_filter($tyresC, static fn (float $v) => $v >= 25.0 && $v <= 165.0);
        if (count($valid) < 4) {
            return [];
        }

        $names = [
            'lf' => 'delantera izquierda',
            'rf' => 'delantera derecha',
            'lr' => 'trasera izquierda',
            'rr' => 'trasera derecha',
        ];

        arsort($valid);
        $hottestKey = (string) array_key_first($valid);
        $hottestVal = (float) current($valid);
        $avgVal = array_sum($valid) / count($valid);
        $callsign = $user->spokenCallsign();

        if ($hottestVal >= 97.0) {
            $deg = (int) round($hottestVal);
            $text = "Chequeo de gomas {$callsign}: has calentado la {$names[$hottestKey]} hasta {$deg} grados. Intenta suavizar giro en apoyo.";
        } elseif ($lapsCompleted >= 2 && $hottestVal <= 68.0) {
            $deg = (int) round($avgVal);
            $text = "Chequeo en boxes: neumáticos fríos en torno a {$deg} grados, puedes atacar con más confianza.";
        } else {
            $deg = (int) round($avgVal);
            $text = "Buen trabajo con las gomas {$callsign}: las cuatro ruedas en ventana óptima alrededor de {$deg} grados.";
        }

        return [$this->makeCallout('pit_tyres_'.substr(md5($text), 0, 8), $text, $radio)];
    }

    /**
     * Synthesize an interactive test sample for the web Settings panel or System Tray.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{url: string, sha256: string, text: string, volume: int}
     */
    public function buildPreviewClip(User $user, array $overrides = []): array
    {
        $radio = array_merge($user->radioSettings(), $overrides);
        $callsign = $user->spokenCallsign();
        $text = "Buenas {$callsign}, radio conectada en Spa. Pista seca a 24 grados, sales con 45 litros. Tu referencia es dos diecisiete ocho, ¡buena tanda!";

        $clip = $this->voiceManager->ensureAudioClip($text, $radio);

        return [
            'url' => $clip['url'],
            'sha256' => $clip['sha256'],
            'text' => $text,
            'volume' => (int) ($radio['volume'] ?? 85),
        ];
    }

    /**
     * Format milliseconds into natural Spanish race-engineer speech (e.g. 107240 -> "uno cuarenta y siete dos").
     */
    public function spokenLapTime(int $ms): string
    {
        $totalTenths = (int) round($ms / 100.0);
        $minutes = intdiv($totalTenths, 600);
        $remTenths = $totalTenths % 600;
        $seconds = intdiv($remTenths, 10);
        $tenths = $remTenths % 10;

        $secStr = $seconds < 10 ? "cero {$seconds}" : (string) $seconds;

        if ($minutes > 0) {
            $minWord = $minutes === 1 ? 'uno' : (string) $minutes;
            return "{$minWord} {$secStr} {$tenths}";
        }

        return "{$seconds} coma {$tenths}";
    }

    /**
     * Format a delta in milliseconds into concise Spanish tenths/seconds (e.g. 320 -> "tres décimas").
     */
    public function spokenDelta(int $deltaMs): string
    {
        $absMs = abs($deltaMs);
        $tenths = max(1, (int) round($absMs / 100.0));

        if ($tenths < 10) {
            return $tenths === 1 ? 'una décima' : "{$tenths} décimas";
        }

        $sec = intdiv($tenths, 10);
        $rem = $tenths % 10;
        $secLabel = $sec === 1 ? 'un segundo' : "{$sec} segundos";

        if ($rem === 0) {
            return $secLabel;
        }

        $decLabel = $rem === 1 ? 'una décima' : "{$rem} décimas";
        return "{$secLabel} y {$decLabel}";
    }

    /**
     * @param  array<string, mixed>|null  $previousPlan
     * @return array{name: string, gain_ms: int}|null
     */
    private function detectFocusCornerImprovement(Lap $lap, ?array $previousPlan): ?array
    {
        if ($previousPlan === null || empty($previousPlan['focus_turn']) || ! isset($previousPlan['avg_time_loss_ms'])) {
            return null;
        }

        $targetTurn = (int) $previousPlan['focus_turn'];
        $prevAvgLoss = (int) $previousPlan['avg_time_loss_ms'];

        foreach ($lap->corner_metrics ?? [] as $cm) {
            if ((int) ($cm['turn'] ?? 0) === $targetTurn && isset($cm['time_loss_ms'])) {
                $gainMs = $prevAvgLoss - (int) $cm['time_loss_ms'];
                if ($gainMs >= 120) {
                    return [
                        'name' => (string) ($previousPlan['focus_turn_name'] ?? $cm['name'] ?? "la curva {$targetTurn}"),
                        'gain_ms' => $gainMs,
                    ];
                }
            }
        }

        return null;
    }

    private function isTurnOneCloseToStartLine(?Track $track): bool
    {
        if ($track === null || empty($track->corners)) {
            return false;
        }

        $first = $track->corners[0] ?? null;
        if (! is_array($first)) {
            return false;
        }

        $startPct = (float) ($first['start_pct'] ?? 1.0);
        return $startPct > 0.94 || $startPct < 0.045;
    }

    private function shortTrackName(string $name): string
    {
        $clean = preg_replace('/\s*[—\-]\s*.*$/u', '', trim($name)) ?? trim($name);
        $clean = str_ireplace(['Circuit de ', 'Autodromo Nazionale ', 'Autódromo '], '', $clean);
        return trim($clean) !== '' ? trim($clean) : $name;
    }

    private function shortDriverName(?string $driver): string
    {
        $trimmed = trim((string) $driver);
        if ($trimmed === '') {
            return '';
        }
        return Str::before($trimmed, ' ');
    }

    private function spokenSectorLabel(int $sectorNumber): string
    {
        return match ($sectorNumber) {
            1 => 'primer parcial',
            2 => 'segundo parcial',
            3 => 'tercer parcial',
            4 => 'cuarto parcial',
            5 => 'quinto parcial',
            default => "parcial {$sectorNumber}",
        };
    }

    /**
     * @param  array<string, mixed>  $radio
     * @return array{id: string, text: string, audio_url: string, audio_sha256: string}
     */
    private function makeCallout(string $id, string $text, array $radio): array
    {
        $clip = $this->voiceManager->ensureAudioClip($text, $radio);

        return [
            'id' => $id,
            'text' => $text,
            'audio_url' => $clip['url'],
            'audio_sha256' => $clip['sha256'],
        ];
    }
}
