<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'driver_callsign' => $user->driver_callsign,
                    'effective_callsign' => $user->effectiveCallsign(),
                    'callsign_phonetic' => $user->callsign_phonetic,
                    'last_client_seen_at' => $user->last_client_seen_at?->toIso8601String(),
                    'client_state' => $user->preferences['client_state'] ?? null,
                    'client_track' => $user->preferences['client_track'] ?? null,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'plain_api_token' => fn () => $request->session()->get('plain_api_token'),
            ],
        ];
    }
}
