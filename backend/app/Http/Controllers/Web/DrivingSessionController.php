<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DrivingSession;
use App\Services\Reports\ProSessionReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DrivingSessionController extends Controller
{
    public function __construct(
        private readonly ProSessionReportService $reportService,
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

        return Inertia::render('Sessions/Show', [
            'session' => $session,
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
}
