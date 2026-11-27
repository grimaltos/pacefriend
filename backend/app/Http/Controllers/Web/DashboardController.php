<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DrivingSession;
use App\Models\ReferenceLap;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $recentSessions = DrivingSession::query()
            ->with(['track:id,name,slug', 'activeReferenceLap:id,label,lap_time_ms,track_temp_c', 'report:id,driving_session_id,status'])
            ->where('user_id', $user->id)
            ->orderByDesc('started_at')
            ->limit(6)
            ->get();

        $myRefsCount = ReferenceLap::query()->where('user_id', $user->id)->count();
        $globalRefsCount = ReferenceLap::query()->whereNull('user_id')->count();

        return Inertia::render('Dashboard', [
            'stats' => [
                'total_sessions' => DrivingSession::query()->where('user_id', $user->id)->count(),
                'clean_laps' => (int) DrivingSession::query()->where('user_id', $user->id)->sum('clean_laps_count'),
                'my_references' => $myRefsCount,
                'global_references' => $globalRefsCount,
                'tokens_count' => $user->tokens()->count(),
            ],
            'recentSessions' => $recentSessions,
        ]);
    }
}
