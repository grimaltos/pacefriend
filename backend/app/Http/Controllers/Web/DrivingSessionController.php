<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DrivingSession;
use App\Models\Lap;
use App\Models\ReferenceLap;
use App\Services\Reports\ProSessionReportService;
use App\Services\Telemetry\TelemetryNormalizerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DrivingSessionController extends Controller
{
    public function __construct(
        private readonly ProSessionReportService $reportService,
        private readonly TelemetryNormalizerService $normalizer,
    ) {}

    public function index(Request $request): Response
    {
        $sessions = DrivingSession::query()
            ->with([
                'track:id,name,slug',
                'activeReferenceLap:id,label,lap_time_ms,track_temp_c,is_wet',
                'report:id,driving_session_id,status,generated_at',
            ])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('started_at')
            ->get();

        return Inertia::render('Sessions/Index', [
            'sessions' => $sessions,
        ]);
    }

    public function show(Request $request, DrivingSession $session): Response
    {
        abort_unless($session->user_id === $request->user()->id, 403);

        $session->load([
            'track:id,name,slug,length_km,corners,sectors',
            'activeReferenceLap:id,label,lap_time_ms,track_temp_c,air_temp_c,is_wet,fuel_level_l,fuel_used_l,source',
            'laps:id,driving_session_id,lap_number,lap_time_ms,is_clean,is_complete,incident_delta,track_temp_c,air_temp_c,is_wet,fuel_level_l,fuel_used_l,corner_metrics,sector_metrics,created_at',
            'report',
        ]);

        $trajectorySuite = $this->resolveInteractiveTrajectorySuite($session);

        return Inertia::render('Sessions/Show', [
            'session' => $session,
            'trajectorySuite' => $trajectorySuite,
        ]);
    }

    public function finish(Request $request, DrivingSession $session): RedirectResponse
    {
        abort_unless($session->user_id === $request->user()->id, 403);

        if ($session->ended_at === null) {
            $session->update(['ended_at' => now()]);
        }

        return back()->with('success', 'Sesión marcada como finalizada. Ya puedes generar tu Informe PRO.');
    }

    public function generateReport(Request $request, DrivingSession $session): RedirectResponse
    {
        abort_unless($session->user_id === $request->user()->id, 403);

        if ($session->ended_at === null) {
            $session->update(['ended_at' => now()]);
        }

        $this->reportService->generateForSession($session);

        return back()->with('success', 'Informe PRO generado por tu ingeniero de pista.');
    }

    /**
     * Build the interactive Full-Circuit + Per-Corner 2D Trajectory Viewer payload,
     * comparing the latest lap against either the matched External ReferenceLap (if it has GPS)
     * or the driver's own Best Session Lap in Self-Reference mode.
     *
     * @return array<string, mixed>|null
     */
    private function resolveInteractiveTrajectorySuite(DrivingSession $session): ?array
    {
        $latestLap = Lap::query()
            ->where('driving_session_id', $session->id)
            ->whereNotNull('samples')
            ->orderByDesc('is_complete')
            ->orderByDesc('id')
            ->first(['id', 'lap_number', 'samples']);

        if ($latestLap === null || empty($latestLap->samples)) {
            return null;
        }

        $targetPoints = [];
        $targetLabel = 'Auto-Referencia de Sesión';

        if ($session->active_reference_lap_id !== null) {
            $fullRef = ReferenceLap::query()->find($session->active_reference_lap_id);
            if ($fullRef !== null) {
                $hasRefGps = collect($fullRef->normalized_points ?? [])->contains(
                    static fn (array $p) => isset($p['lat'], $p['lon']) && (abs((float) $p['lat']) > 0.001 || abs((float) $p['lon']) > 0.001)
                );
                if ($hasRefGps) {
                    $targetPoints = $fullRef->normalized_points ?? [];
                    $targetLabel = "Target: {$fullRef->label}";
                }
            }
        }

        // Self-Reference fallback: compare against driver's fastest clean lap in this session
        if ($targetPoints === []) {
            $bestSelfLap = Lap::query()
                ->where('driving_session_id', $session->id)
                ->where('is_complete', true)
                ->whereNotNull('samples')
                ->orderByDesc('is_clean')
                ->orderBy('lap_time_ms')
                ->first(['id', 'lap_number', 'samples']);

            if ($bestSelfLap !== null && ! empty($bestSelfLap->samples)) {
                $targetPoints = $bestSelfLap->samples;
                $targetLabel = "Tu Mejor Vuelta (#{$bestSelfLap->lap_number})";
            } else {
                $targetPoints = $latestLap->samples;
            }
        }

        return $this->normalizer->buildInteractiveTrajectorySuite(
            $targetPoints,
            $latestLap->samples,
            $session->track?->corners ?? [],
            $targetLabel
        );
    }
}
