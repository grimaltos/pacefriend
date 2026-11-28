<?php

namespace App\Services\Coaching;

use App\Models\DrivingSession;
use App\Models\Lap;
use App\Models\ReferenceLap;
use App\Models\Track;
use App\Models\User;
use App\Services\Voice\VoiceSynthesisManager;
use Illuminate\Support\Str;

class RaceStrategyService
{
    public function __construct(
        private readonly VoiceSynthesisManager $voiceManager,
    ) {}

    /**
     * Build specialized Grid / Formation-Lap briefing for Race mode or Out-Lap Traffic briefing for Qualy.
     *
     * @param  array<string, mixed>  $raceCtx
     * @return array{callouts: array<int, array{id: string, text: string, audio_url: string, audio_sha256: string}>, pit_commands: array<int, array{action: string, value: int}>}
     */
    public function buildSessionBriefing(
        User $user,
        Track $track,
        float $trackTempC,
        float $airTempC,
        bool $isWet,
        ?float $fuelLevelL,
        ?ReferenceLap $referenceLap,
        array $raceCtx
    ): array {
        $radio = $user->radioSettings();
        $callsign = $user->spokenCallsign();
        $sessionType = (string) ($raceCtx['session_type'] ?? 'practice');
        $tTrack = (int) round($trackTempC);
        $surface = $isWet ? 'mojada' : 'seca';

        if ($sessionType === 'qualify') {
            $parts = [
                "Clasificación en {$this->shortTrackName($track->name)}, {$callsign}.",
                "Pista {$surface} a {$tTrack} grados.",
            ];
            $gapAhead = (float) ($raceCtx['gap_ahead_s'] ?? 0.0);
            if ($gapAhead > 0.2 && $gapAhead < 4.5) {
                $g = number_format($gapAhead, 1, ',', '');
                $parts[] = "Tienes un coche a {$g} segundos delante; deja hueco antes de lanzar vuelta.";
            } else {
                $parts[] = 'Aire limpio delante, calienta bien frenos y gomas.';
            }

            $text = implode(' ', $parts);

            return [
                'callouts' => [$this->makeCallout('qualy_brief_'.substr(md5($text), 0, 8), $text, $radio)],
                'pit_commands' => [],
            ];
        }

        // Race Mode Grid / Formation Briefing
        $pos = (int) ($raceCtx['class_position'] ?? $raceCtx['overall_position'] ?? 0);
        $parts = [
            "Modo carrera activado, {$callsign}.",
        ];
        if ($pos > 0) {
            $parts[] = "Sales P{$pos} con pista {$surface} a {$tTrack} grados.";
        } else {
            $parts[] = "Pista {$surface} a {$tTrack} grados.";
        }

        $estLapMs = $referenceLap?->lap_time_ms ?? $this->estimateLapTimeMs($track);
        $estFuelPerLap = ($referenceLap?->fuel_used_l !== null && $referenceLap->fuel_used_l > 0.2)
            ? (float) $referenceLap->fuel_used_l
            : max(1.6, round($track->length_km * 0.50, 2));

        $estTotalLaps = $this->estimateRemainingLaps($raceCtx, $estLapMs);

        if ($fuelLevelL !== null && $fuelLevelL > 0.5 && $estTotalLaps !== null && $estTotalLaps > 0) {
            $liters = (int) round($fuelLevelL);
            $fuelLaps = $fuelLevelL / max(0.2, $estFuelPerLap);

            if ($estTotalLaps <= 18 && $fuelLaps < ($estTotalLaps * 0.78)) {
                $fLapsInt = max(1, (int) floor($fuelLaps));
                $parts[] = "¡Atención! Llevas solo {$liters} litros, da para unas {$fLapsInt} vueltas de {$estTotalLaps}. ¡Revisa que no lleves el setup de qualy!";
            } elseif ($fuelLaps >= ($estTotalLaps + 0.35)) {
                $parts[] = "Llevas {$liters} litros, gasolina de sobra hasta la bandera a cuadros.";
            } else {
                $pitLap = max(2, (int) floor($fuelLaps - 0.7));
                $parts[] = "Sales con {$liters} litros; ventana de parada hacia la vuelta {$pitLap}.";
            }
        } elseif ($fuelLevelL !== null && $fuelLevelL > 0.5) {
            $liters = (int) round($fuelLevelL);
            $parts[] = "Sales con {$liters} litros.";
        }

        $incLimit = (int) ($raceCtx['incident_limit'] ?? 0);
        if ($incLimit > 0) {
            $parts[] = "Límite {$incLimit} incidentes. Frenos fríos en la uno, cabeza fría.";
        } else {
            $parts[] = 'Cuidado con gomas y frenos fríos en la salida.';
        }

        $text = implode(' ', $parts);

        return [
            'callouts' => [$this->makeCallout('race_grid_'.substr(md5($text), 0, 8), $text, $radio)],
            'pit_commands' => [],
        ];
    }

    /**
     * Evaluate live race strategy, weather crossover, pit-window proposals, rival pace, and incident limits.
     *
     * @param  array<string, mixed>  $raceCtx
     * @param  array<string, mixed>  $radioFlags
     * @return array{
     *   callouts: array<int, array{id: string, text: string, audio_url: string, audio_sha256: string}>,
     *   pit_commands: array<int, array{action: string, value: int}>,
     *   pending_pit: array<string, mixed>|null
     * }
     */
    public function evaluateRaceLap(
        User $user,
        DrivingSession $session,
        Track $track,
        Lap $lap,
        ?int $previousBestMs,
        ?float $avgFuelUsedL,
        array $raceCtx,
        array &$radioFlags,
        int $straightBrakeDragMs = 0
    ): array {
        $radio = $user->radioSettings();
        $callsign = $user->spokenCallsign();
        $pitMode = $radio['pit_automation_mode']; // 'confirm' | 'auto' | 'manual_voice'
        $coexistCrewChief = (bool) $radio['crewchief_coexistence'];

        $messages = [];
        $pitCommands = [];
        $pendingPit = null;

        // 0. Mechanical brake drag warning always takes priority
        if ($straightBrakeDragMs >= 450 && empty($radioFlags['brake_drag_warned'])) {
            $radioFlags['brake_drag_warned'] = true;
            $messages[] = "Ojo {$callsign}, estás rozando el freno en recta.";
        }

        // 1. Personal Best reinforcement (avoiding noisy every-lap time readout when CrewChief is active)
        if ($lap->is_clean && $previousBestMs !== null && $lap->lap_time_ms < $previousBestMs && empty($raceCtx['in_traffic'])) {
            if (! $coexistCrewChief || ($previousBestMs - $lap->lap_time_ms) >= 200) {
                $messages[] = "Vuelta rápida personal {$callsign}, {$this->spokenLapTime($lap->lap_time_ms)}.";
            }
        } elseif (! $coexistCrewChief && $radio['finish_callout_mode'] === 'full' && $lap->is_clean) {
            $messages[] = "Tiempo {$this->spokenLapTime($lap->lap_time_ms)}.";
        }

        $effFuelPerLap = ($avgFuelUsedL !== null && $avgFuelUsedL > 0.15)
            ? $avgFuelUsedL
            : (($lap->fuel_used_l !== null && $lap->fuel_used_l > 0.15) ? (float) $lap->fuel_used_l : max(1.6, $track->length_km * 0.50));

        $estLapMs = $session->best_lap_time_ms ?? $lap->lap_time_ms ?: $this->estimateLapTimeMs($track);
        $lapsRemain = $this->estimateRemainingLaps($raceCtx, $estLapMs);
        $fuelLevelL = (float) ($raceCtx['fuel_level_l'] ?? $lap->fuel_level_l ?? 0.0);

        // 2. Climatología & Detector de Crossover Seco <-> Lluvia mirando a toda la parrilla
        $playerComp = (int) ($raceCtx['player_compound'] ?? 0); // 0 = Dry, >= 1 = Wet
        $precip = (float) ($raceCtx['precipitation'] ?? 0.0);
        $wetLevel = (int) ($raceCtx['track_wetness_level'] ?? 0);
        $declaredWet = ! empty($raceCtx['weather_declared_wet']);
        $dryAvgMs = (int) ($raceCtx['dry_cars_avg_lap_ms'] ?? 0);
        $wetAvgMs = (int) ($raceCtx['wet_cars_avg_lap_ms'] ?? 0);
        $wetCount = (int) ($raceCtx['wet_cars_count'] ?? 0);
        $dryCount = (int) ($raceCtx['dry_cars_count'] ?? 0);

        $wetIsFaster = $wetCount >= 1 && $dryAvgMs > 0 && $wetAvgMs > 0 && $wetAvgMs < ($dryAvgMs - 900);
        $heavyWetTrack = $declaredWet && $wetLevel >= 4 && $precip >= 0.35;

        if ($playerComp === 0 && ($wetIsFaster || $heavyWetTrack) && empty($radioFlags['wet_crossover_alerted'])) {
            $radioFlags['wet_crossover_alerted'] = true;
            $radioFlags['dry_crossover_alerted'] = false;

            $refuelL = $this->computeRefuelLiters($lapsRemain, $effFuelPerLap, $fuelLevelL);
            $cmds = [
                ['action' => 'compound', 'value' => 1],
                ['action' => 'all_tires', 'value' => 0],
                $refuelL > 0 ? ['action' => 'fuel', 'value' => $refuelL] : ['action' => 'clear_fuel', 'value' => 0],
            ];

            $deltaText = $wetIsFaster
                ? 'los neumáticos de agua ya vienen '.number_format(($dryAvgMs - $wetAvgMs) / 1000.0, 1, ',', '').' segundos más rápido'
                : 'la pista ya está para neumáticos de agua';
            $fuelPhrase = $refuelL > 0 ? " y {$refuelL} litros" : ' sin gasolina extra';

            if ($pitMode === 'auto') {
                $pitCommands = $cmds;
                $messages[] = "¡Crossover de lluvia {$callsign}! {$deltaText}. Te he dejado marcadas gomas de agua{$fuelPhrase} en el box.";
            } elseif ($pitMode === 'confirm') {
                $pendingPit = [
                    'reason' => 'wet_crossover',
                    'summary' => "ruedas de lluvia{$fuelPhrase}",
                    'commands' => $cmds,
                ];
                $messages[] = "¡Crossover de lluvia {$callsign}! {$deltaText}. Recomiendo entrar por gomas de agua{$fuelPhrase}. ¿Confirmas?";
            } else {
                $messages[] = "¡Crossover de lluvia! {$deltaText}.";
            }
        } elseif ($playerComp >= 1 && $wetLevel <= 2 && $precip < 0.05 && $dryCount >= 1 && $wetAvgMs > 0 && $dryAvgMs > 0 && $dryAvgMs < ($wetAvgMs - 900) && empty($radioFlags['dry_crossover_alerted'])) {
            $radioFlags['dry_crossover_alerted'] = true;
            $radioFlags['wet_crossover_alerted'] = false;

            $refuelL = $this->computeRefuelLiters($lapsRemain, $effFuelPerLap, $fuelLevelL);
            $cmds = [
                ['action' => 'compound', 'value' => 0],
                ['action' => 'all_tires', 'value' => 0],
                $refuelL > 0 ? ['action' => 'fuel', 'value' => $refuelL] : ['action' => 'clear_fuel', 'value' => 0],
            ];
            $gainSec = number_format(($wetAvgMs - $dryAvgMs) / 1000.0, 1, ',', '');
            $fuelPhrase = $refuelL > 0 ? " y {$refuelL} litros" : '';

            if ($pitMode === 'auto') {
                $pitCommands = $cmds;
                $messages[] = "¡Pista para seco {$callsign}! Los slicks ya ruedan {$gainSec} segundos más rápido. Slicks{$fuelPhrase} preparados en el box.";
            } elseif ($pitMode === 'confirm') {
                $pendingPit = [
                    'reason' => 'dry_crossover',
                    'summary' => "ruedas de seco{$fuelPhrase}",
                    'commands' => $cmds,
                ];
                $messages[] = "¡Crossover a seco! Los slicks vienen {$gainSec} segundos más rápido. ¿Preparo slicks{$fuelPhrase}?";
            } else {
                $messages[] = "¡Crossover a seco! Los slicks ya son {$gainSec} segundos más rápidos.";
            }
        } elseif ($playerComp === 0 && $precip >= 0.08 && empty($radioFlags['rain_drizzle_warned']) && empty($radioFlags['wet_crossover_alerted'])) {
            $radioFlags['rain_drizzle_warned'] = true;
            $messages[] = 'Empieza a caer agua, pero los slicks siguen siendo el neumático rápido. Mantente fuera.';
        }

        // 3. Estrategia de Gasolina, Lift & Coast y Ventana de Parada
        if ($fuelLevelL > 0.2 && $effFuelPerLap > 0.2 && $lapsRemain !== null && $lapsRemain > 0 && $pendingPit === null && $pitCommands === []) {
            $fuelLapsLeft = $fuelLevelL / $effFuelPerLap;
            $shortfallL = ($lapsRemain * $effFuelPerLap) - $fuelLevelL;

            // Lift & Coast opportunity: within ~1 lap of making it without stopping!
            if ($lapsRemain >= 4 && $shortfallL > 0.20 && $shortfallL <= ($effFuelPerLap * 0.95) && empty($radioFlags['lift_coast_proposed'])) {
                $radioFlags['lift_coast_proposed'] = true;
                $savePerLap = number_format($shortfallL / $lapsRemain, 2, ',', '');
                $messages[] = "Oportunidad táctica: te falta poco más de un litro; si ahorras {$savePerLap} por vuelta haciendo lift and coast llegas sin parar.";
            } elseif ($fuelLapsLeft <= 2.3 && $lapsRemain > ($fuelLapsLeft + 0.7) && empty($radioFlags['pit_window_proposed'])) {
                $radioFlags['pit_window_proposed'] = true;
                $refuelL = $this->computeRefuelLiters($lapsRemain, $effFuelPerLap, $fuelLevelL);
                $stintLaps = (int) ($raceCtx['stint_laps'] ?? $session->laps_count);
                $skipTyres = $playerComp === 0 && ! $lap->is_wet && $stintLaps < 18;

                $cmds = [
                    $skipTyres ? ['action' => 'clear_tires', 'value' => 0] : ['action' => 'all_tires', 'value' => 0],
                    ['action' => 'fuel', 'value' => max(1, $refuelL)],
                ];
                $tyreDesc = $skipTyres ? 'sin cambiar ruedas' : 'cambiando las cuatro gomas';

                if ($pitMode === 'auto') {
                    $pitCommands = $cmds;
                    $messages[] = "Ventana de box abierta: te he preparado {$refuelL} litros {$tyreDesc}.";
                } elseif ($pitMode === 'confirm') {
                    $pendingPit = [
                        'reason' => 'fuel_window',
                        'summary' => "{$refuelL} litros {$tyreDesc}",
                        'commands' => $cmds,
                    ];
                    $messages[] = "Ventana de parada abierta: recomiendo meter {$refuelL} litros {$tyreDesc}. ¿Lo dejo listo?";
                } else {
                    $messages[] = "Reserva de gasolina: necesitas {$refuelL} litros para llegar al final.";
                }
            } elseif ($fuelLapsLeft <= 1.2 && $lapsRemain > 1) {
                $messages[] = "¡Box en esta vuelta {$callsign}, te queda gasolina para una vuelta!";
            }
        }

        // 4. Lectura Táctica de Rivales, Undercut y Tráfico Multiclase
        $gapAhead = (float) ($raceCtx['gap_ahead_s'] ?? 0.0);
        $prevGapAhead = isset($radioFlags['_prev_gap_ahead_s']) ? (float) $radioFlags['_prev_gap_ahead_s'] : 99.0;
        $aheadOnPit = ! empty($raceCtx['ahead_on_pit']);
        $pos = (int) ($raceCtx['class_position'] ?? 0);

        if ($aheadOnPit && $prevGapAhead <= 4.2 && empty($radioFlags['undercut_lap_'.$lap->lap_number])) {
            $radioFlags['undercut_lap_'.$lap->lap_number] = true;
            $messages[] = '¡Tu rival de delante entra a boxes! Tira a fondo esta vuelta para hacerle el overcut.';
        } elseif ($pos > 1 && $gapAhead >= 1.5 && $gapAhead <= 6.5 && ! $aheadOnPit) {
            $aheadLastMs = (int) ($raceCtx['ahead_last_lap_ms'] ?? 0);
            $lastRivalCallLap = (int) ($radioFlags['_last_rival_call_lap'] ?? -5);
            if ($aheadLastMs > 0 && $lap->is_clean && ($aheadLastMs - $lap->lap_time_ms) >= 350 && ($lap->lap_number - $lastRivalCallLap) >= 3) {
                $radioFlags['_last_rival_call_lap'] = $lap->lap_number;
                $gainTenths = (int) round(($aheadLastMs - $lap->lap_time_ms) / 100.0);
                $catchLaps = max(1, (int) ceil(($gapAhead * 1000.0) / max(200, $aheadLastMs - $lap->lap_time_ms)));
                if ($catchLaps <= 8) {
                    $aheadPos = $pos - 1;
                    $g = number_format($gapAhead, 1, ',', '');
                    $messages[] = "P{$aheadPos} a {$g} delante, le recortas {$gainTenths} décimas por vuelta; lo cazas en {$catchLaps} vueltas.";
                }
            }
        }
        $radioFlags['_prev_gap_ahead_s'] = $gapAhead > 0 ? round($gapAhead, 2) : 99.0;

        $gapBehind = (float) ($raceCtx['gap_behind_s'] ?? 0.0);
        if ($pos > 0 && $gapBehind >= 1.0 && $gapBehind <= 3.8) {
            $behindLastMs = (int) ($raceCtx['behind_last_lap_ms'] ?? 0);
            $lastBehindCallLap = (int) ($radioFlags['_last_behind_call_lap'] ?? -5);
            if ($behindLastMs > 0 && ($lap->lap_time_ms - $behindLastMs) >= 450 && ($lap->lap_number - $lastBehindCallLap) >= 3) {
                $radioFlags['_last_behind_call_lap'] = $lap->lap_number;
                $behindPos = $pos + 1;
                $g = number_format($gapBehind, 1, ',', '');
                $messages[] = "Atento al espejo: P{$behindPos} detrás a {$g}, viene medio segundo más rápido.";
            }
        }

        $fasterClassSec = (float) ($raceCtx['faster_class_closing_s'] ?? 0.0);
        if (! empty($raceCtx['is_multiclass']) && $fasterClassSec >= 0.4 && $fasterClassSec <= 4.0) {
            $s = (int) round($fasterClassSec);
            $messages[] = "Tráfico de categoría superior llegando a {$s} segundos detrás.";
        }

        // 5. Supervivencia: Límite de Incidentes (x)
        $incCount = (int) ($raceCtx['incident_count'] ?? 0);
        $incLimit = (int) ($raceCtx['incident_limit'] ?? 0);
        if ($incLimit > 0 && $incCount > 0) {
            $margin = $incLimit - $incCount;
            $warnKey = "inc_warn_{$incCount}";
            if ($margin >= 1 && $margin <= 3 && empty($radioFlags[$warnKey])) {
                $radioFlags[$warnKey] = true;
                $messages[] = "Cuidado {$callsign}, llevas {$incCount} incidentes de {$incLimit}; solo {$margin} de margen, protege límites.";
            }
        }

        if ($messages === []) {
            return [
                'callouts' => [],
                'pit_commands' => $pitCommands,
                'pending_pit' => $pendingPit,
            ];
        }

        $fullText = implode(' ', $messages);

        return [
            'callouts' => [$this->makeCallout("race_lap_{$lap->id}_".substr(md5($fullText), 0, 6), $fullText, $radio)],
            'pit_commands' => $pitCommands,
            'pending_pit' => $pendingPit,
        ];
    }

    /**
     * Build an urgent ahead-hazard warning when a car spins or goes off-track right ahead.
     *
     * @return array<int, array{id: string, text: string, audio_url: string, audio_sha256: string}>
     */
    public function buildAheadHazardCallout(User $user, int $aheadMeters): array
    {
        $radio = $user->radioSettings();
        $roundedMeters = max(100, (int) round($aheadMeters / 50.0) * 50);
        $text = "¡Atención delante! Coche fuera de pista a {$roundedMeters} metros.";

        return [$this->makeCallout('hazard_'.substr(md5($text.microtime()), 0, 8), $text, $radio)];
    }

    /**
     * @param  array<string, mixed>  $raceCtx
     */
    public function estimateRemainingLaps(array $raceCtx, int $estLapMs): ?int
    {
        $lapsRemain = (int) ($raceCtx['laps_remain'] ?? 0);
        if ($lapsRemain > 0 && $lapsRemain < 2000) {
            return $lapsRemain;
        }

        $timeRemainSec = (float) ($raceCtx['time_remain_sec'] ?? 0.0);
        if ($timeRemainSec > 5.0 && $timeRemainSec < 90000.0 && $estLapMs > 15000) {
            $lapSec = $estLapMs / 1000.0;
            // Include +1 safety lap in case race leader crosses start/finish right before 0:00
            return (int) ceil($timeRemainSec / $lapSec) + 1;
        }

        return null;
    }

    public function computeRefuelLiters(?int $lapsRemain, float $fuelPerLap, float $currentFuelL): int
    {
        if ($lapsRemain === null || $lapsRemain <= 0 || $fuelPerLap <= 0.1) {
            return 0;
        }
        $needed = ($lapsRemain * $fuelPerLap) - $currentFuelL + max(0.8, $fuelPerLap * 0.45);

        return $needed > 0.5 ? (int) ceil($needed) : 0;
    }

    private function estimateLapTimeMs(Track $track): int
    {
        $km = max(1.5, $track->length_km);

        return (int) round(($km * 1000.0 / 48.0) * 1000.0);
    }

    private function shortTrackName(string $name): string
    {
        $clean = trim(Str::before($name, '—'));
        $clean = preg_replace('/^(Circuit de |Autodromo Nazionale |Autodromo Internazionale |WeatherTech Raceway )/i', '', $clean) ?? $clean;

        return trim($clean);
    }

    private function spokenLapTime(int $ms): string
    {
        $totalSec = intdiv($ms, 1000);
        $min = intdiv($totalSec, 60);
        $sec = $totalSec % 60;
        $tenths = intdiv($ms % 1000, 100);

        return $min > 0 ? "{$min} {$sec} punto {$tenths}" : "{$sec} punto {$tenths}";
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
