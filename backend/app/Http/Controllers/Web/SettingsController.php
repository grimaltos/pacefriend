<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Garage61\Garage61ClientService;
use App\Services\Garage61\Garage61OAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SettingsController extends Controller
{
    public function edit(
        Request $request,
        Garage61ClientService $g61Client,
        Garage61OAuthService $g61OAuth
    ): Response {
        $user = $request->user();
        $g61Status = null;

        if (filled($user->garage61_token)) {
            try {
                $validToken = $g61OAuth->resolveValidAccessToken($user);
                $me = $g61Client->me($validToken);
                $g61Status = [
                    'auth_type' => $user->garage61_auth_type ?: 'pat',
                    'driver' => trim(($me['firstName'] ?? '').' '.($me['lastName'] ?? '')) ?: ($me['slug'] ?? ''),
                    'plan' => $me['subscriptionPlan'] ?? 'free',
                    'permissions' => $me['apiPermissions'] ?? [],
                    'teams' => array_map(static fn (array $t) => $t['name'] ?? $t['slug'] ?? '', $me['teams'] ?? []),
                ];
            } catch (RuntimeException $e) {
                $g61Status = ['error' => $e->getMessage()];
            }
        }

        return Inertia::render('Settings/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'driver_callsign' => $user->driver_callsign,
                'effective_callsign' => $user->effectiveCallsign(),
                'callsign_phonetic' => $user->callsign_phonetic,
                'preferences' => $user->preferences ?? [],
                'garage61_connected' => filled($user->garage61_token),
                'garage61_auth_type' => $user->garage61_auth_type ?: 'pat',
                'garage61_oauth_available' => $g61OAuth->isConfigured(),
                'garage61_callback_url' => $g61OAuth->redirectUri(),
                'garage61_status' => $g61Status,
                'autopilot_enabled' => (bool) $user->autopilot_enabled,
                'reference_scope' => $user->reference_scope ?: 'team_then_public',
                'autopilot_mode' => in_array($user->autopilot_mode, ['fastest', 'alien'], true) ? 'fastest' : 'progressive',
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

    public function redirectToGarage61(Request $request, Garage61OAuthService $g61OAuth): RedirectResponse
    {
        try {
            $authUrl = $g61OAuth->createAuthorizationUrl($request);
        } catch (RuntimeException $e) {
            return redirect('/settings')->with('error', $e->getMessage());
        }

        return redirect()->away($authUrl);
    }

    public function handleGarage61Callback(Request $request, Garage61OAuthService $g61OAuth): RedirectResponse
    {
        try {
            $me = $g61OAuth->handleCallback($request, $request->user());
        } catch (RuntimeException $e) {
            return redirect('/settings')->with('error', $e->getMessage());
        }

        $driver = trim(($me['firstName'] ?? '').' '.($me['lastName'] ?? '')) ?: ($me['slug'] ?? 'conectada');

        return redirect('/settings')->with('success', "¡Cuenta de Garage 61 ({$driver}) vinculada automáticamente por OAuth2!");
    }

    public function updateGarage61(Request $request, Garage61ClientService $g61Client): RedirectResponse
    {
        $validated = $request->validate([
            'garage61_token' => ['nullable', 'string', 'max:1024'],
            'clear_token' => ['nullable', 'boolean'],
            'autopilot_enabled' => ['required', 'boolean'],
            'reference_scope' => ['nullable', 'in:team_only,team_then_public,public_all'],
            'autopilot_mode' => ['required', 'in:progressive,fastest,team,alien'],
            'autopilot_delta_ms' => ['required', 'integer', 'min:400', 'max:3000'],
        ]);

        $user = $request->user();
        $scope = $validated['reference_scope'] ?? ($validated['autopilot_mode'] === 'team' ? 'team_only' : ($user->reference_scope ?: 'team_then_public'));
        $mode = in_array($validated['autopilot_mode'], ['fastest', 'alien', 'team'], true) ? 'fastest' : 'progressive';

        $updates = [
            'autopilot_enabled' => (bool) $validated['autopilot_enabled'],
            'reference_scope' => $scope,
            'autopilot_mode' => $mode,
            'autopilot_delta_ms' => (int) $validated['autopilot_delta_ms'],
        ];

        if (! empty($validated['clear_token'])) {
            $updates['garage61_token'] = null;
            $updates['garage61_auth_type'] = null;
            $updates['garage61_refresh_token'] = null;
            $updates['garage61_token_expires_at'] = null;
        } elseif (! empty($validated['garage61_token'])) {
            try {
                $profile = $g61Client->me($validated['garage61_token']);
            } catch (RuntimeException $e) {
                return back()->withErrors(['garage61_token' => $e->getMessage()]);
            }
            $updates['garage61_token'] = trim($validated['garage61_token']);
            $updates['garage61_auth_type'] = 'pat';
            $updates['garage61_refresh_token'] = null;
            $updates['garage61_token_expires_at'] = null;
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
