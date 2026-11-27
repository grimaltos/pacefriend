<?php

namespace App\Services\Garage61;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class Garage61OAuthService
{
    public function __construct(
        private readonly Garage61ClientService $apiClient,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('pacefriend.garage61.oauth.client_id'))
            && filled(config('pacefriend.garage61.oauth.client_secret'));
    }

    public function redirectUri(): string
    {
        $configured = trim((string) config('pacefriend.garage61.oauth.redirect_uri', ''));
        return $configured !== '' ? $configured : url('/settings/garage61/callback');
    }

    /**
     * Build RFC 6749 + PKCE (S256) authorization URL for Garage 61.
     */
    public function createAuthorizationUrl(Request $request): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Faltan GARAGE61_CLIENT_ID y GARAGE61_CLIENT_SECRET en la configuración del servidor.');
        }

        $state = Str::random(40);
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $request->session()->put('g61_oauth_state', $state);
        $request->session()->put('g61_oauth_verifier', $codeVerifier);

        $authBase = (string) config('pacefriend.garage61.oauth.authorize_url', 'https://garage61.net/app/account/oauth');
        $scopes = (string) config('pacefriend.garage61.oauth.scopes', 'driving_data analyses');

        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('pacefriend.garage61.oauth.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => $scopes,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return "{$authBase}?{$params}";
    }

    /**
     * Exchange OAuth2 authorization code for access + refresh tokens and persist on User.
     *
     * @return array<string, mixed>
     */
    public function handleCallback(Request $request, User $user): array
    {
        if ($request->filled('error')) {
            $desc = (string) $request->query('error_description', $request->query('error'));
            throw new RuntimeException("Autorización cancelada o rechazada en Garage 61: {$desc}");
        }

        $expectedState = (string) $request->session()->pull('g61_oauth_state', '');
        $verifier = (string) $request->session()->pull('g61_oauth_verifier', '');
        $givenState = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($expectedState === '' || ! hash_equals($expectedState, $givenState)) {
            throw new RuntimeException('Estado OAuth2 inválido o caducado. Intenta conectar de nuevo.');
        }
        if ($code === '') {
            throw new RuntimeException('Garage 61 no devolvió código de autorización.');
        }

        $tokenUrl = (string) config('pacefriend.garage61.oauth.token_url', 'https://garage61.net/api/oauth/token');
        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => (string) config('pacefriend.garage61.oauth.client_id'),
            'client_secret' => (string) config('pacefriend.garage61.oauth.client_secret'),
        ];
        if ($verifier !== '') {
            $payload['code_verifier'] = $verifier;
        }

        $resp = Http::asForm()->acceptJson()->timeout(15)->post($tokenUrl, $payload);
        if (! $resp->successful()) {
            $msg = (string) ($resp->json('error_description') ?? $resp->json('message') ?? 'HTTP '.$resp->status());
            throw new RuntimeException("Error al canjear token OAuth2 con Garage 61: {$msg}");
        }

        $data = $resp->json() ?? [];
        $accessToken = (string) ($data['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Respuesta OAuth2 de Garage 61 sin access_token.');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $user->update([
            'garage61_token' => $accessToken,
            'garage61_auth_type' => 'oauth',
            'garage61_refresh_token' => ! empty($data['refresh_token']) ? (string) $data['refresh_token'] : $user->garage61_refresh_token,
            'garage61_token_expires_at' => $expiresIn > 0 ? now()->addSeconds(max(60, $expiresIn - 60)) : null,
        ]);

        return $this->apiClient->me($accessToken);
    }

    /**
     * Return a valid Bearer token for User, transparently refreshing OAuth2 tokens when expired.
     */
    public function resolveValidAccessToken(User $user): string
    {
        $token = trim((string) $user->garage61_token);
        if ($token === '') {
            throw new RuntimeException('Conecta primero tu cuenta de Garage 61 en Ajustes de Piloto.');
        }

        $needsRefresh = $user->garage61_auth_type === 'oauth'
            && $user->garage61_token_expires_at !== null
            && $user->garage61_token_expires_at->isPast()
            && filled($user->garage61_refresh_token)
            && $this->isConfigured();

        if (! $needsRefresh) {
            return $token;
        }

        $tokenUrl = (string) config('pacefriend.garage61.oauth.token_url', 'https://garage61.net/api/oauth/token');
        $resp = Http::asForm()->acceptJson()->timeout(15)->post($tokenUrl, [
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $user->garage61_refresh_token,
            'client_id' => (string) config('pacefriend.garage61.oauth.client_id'),
            'client_secret' => (string) config('pacefriend.garage61.oauth.client_secret'),
        ]);

        if (! $resp->successful()) {
            throw new RuntimeException('Tu sesión OAuth2 de Garage 61 ha caducado. Vuelve a pulsar "Conectar con Garage 61" en Ajustes.');
        }

        $data = $resp->json() ?? [];
        $newAccess = (string) ($data['access_token'] ?? '');
        if ($newAccess === '') {
            return $token;
        }

        $expiresIn = (int) ($data['expires_in'] ?? 3600);
        $user->update([
            'garage61_token' => $newAccess,
            'garage61_refresh_token' => ! empty($data['refresh_token']) ? (string) $data['refresh_token'] : $user->garage61_refresh_token,
            'garage61_token_expires_at' => $expiresIn > 0 ? now()->addSeconds(max(60, $expiresIn - 60)) : null,
        ]);

        return $newAccess;
    }
}
