<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Garage61\Garage61ClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'driver_callsign' => $user->driver_callsign,
                'effective_callsign' => $user->effectiveCallsign(),
                'callsign_phonetic' => $user->callsign_phonetic,
                'preferences' => $user->preferences ?? [],
                'garage61_connected' => filled($user->garage61_token),
                'autopilot_enabled' => (bool) $user->autopilot_enabled,
                'autopilot_mode' => $user->autopilot_mode ?: 'progressive',
                'autopilot_delta_ms' => $user->autopilot_delta_ms ?: 1000,
            ],
            'tokens' => $user->tokens()
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'last_used_at', 'created_at']),
            'apiBaseUrl' => url('/'),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'driver_callsign' => ['nullable', 'string', 'max:64'],
            'callsign_phonetic' => ['nullable', 'string', 'max:128'],
        ]);

        $user = $request->user();
        $user->update([
            'name' => $validated['name'],
            'driver_callsign' => $validated['driver_callsign'] ?: null,
            'callsign_phonetic' => $validated['callsign_phonetic'] ?: null,
        ]);

        return back()->with('success', "Perfil actualizado. El ingeniero te llamará \"{$user->effectiveCallsign()}\".");
    }

    public function updateGarage61(Request $request, Garage61ClientService $g61Client): RedirectResponse
    {
        $validated = $request->validate([
            'garage61_token' => ['nullable', 'string', 'max:1024'],
            'clear_token' => ['nullable', 'boolean'],
            'autopilot_enabled' => ['required', 'boolean'],
            'autopilot_mode' => ['required', 'in:progressive,team,alien'],
            'autopilot_delta_ms' => ['required', 'integer', 'min:400', 'max:3000'],
        ]);

        $user = $request->user();
        $updates = [
            'autopilot_enabled' => (bool) $validated['autopilot_enabled'],
            'autopilot_mode' => $validated['autopilot_mode'],
            'autopilot_delta_ms' => (int) $validated['autopilot_delta_ms'],
        ];

        if (! empty($validated['clear_token'])) {
            $updates['garage61_token'] = null;
        } elseif (! empty($validated['garage61_token'])) {
            try {
                $profile = $g61Client->me($validated['garage61_token']);
            } catch (RuntimeException $e) {
                return back()->withErrors(['garage61_token' => $e->getMessage()]);
            }
            $updates['garage61_token'] = trim($validated['garage61_token']);
            $g61Nick = trim(($profile['firstName'] ?? '').' '.($profile['lastName'] ?? '')) ?: ($profile['nickName'] ?? 'conectada');
        }

        $user->update($updates);

        $suffix = isset($g61Nick) ? " Cuenta G61 verificada ({$g61Nick})." : '';
        return back()->with('success', "Configuración de Garage 61 y Autopilot guardada.{$suffix}");
    }

    public function createToken(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token_name' => ['required', 'string', 'max:80'],
        ]);

        $token = $request->user()->createToken($validated['token_name']);

        return back()
            ->with('plain_api_token', $token->plainTextToken)
            ->with('success', 'Token de conexión generado. Cópialo ahora para tu cliente local en Windows.');
    }

    public function revokeToken(Request $request, int $tokenId): RedirectResponse
    {
        $request->user()->tokens()->where('id', $tokenId)->delete();

        return back()->with('success', 'Token revocado correctamente.');
    }
}
