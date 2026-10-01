<?php

namespace Modules\User\Services\Auth;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Saas\Support\TenantContext;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;

class EnterpriseSsoService
{
    private const STATE_TTL_SECONDS = 600;
    private const HANDOFF_TTL_SECONDS = 60;

    public function __construct(
        private readonly AuthServiceInterface $auth,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function providers(): array
    {
        return collect(config('services.sso.providers', []))
            ->map(fn (array $provider, string $key) => [
                'key' => $key,
                'name' => $provider['name'] ?? Str::headline($key),
                'enabled' => $this->providerEnabled($provider),
            ])
            ->filter(fn (array $provider) => $provider['enabled'])
            ->values()
            ->all();
    }

    public function configuredProvider(string $provider): array
    {
        $config = config("services.sso.providers.{$provider}");

        abort_if(! is_array($config) || ! $this->providerEnabled($config), Response::HTTP_NOT_FOUND, __('user::auth.sso_provider_unavailable'));

        return $this->normalizeProviderConfig($config);
    }

    public function redirectUrl(string $provider, string $frontendRedirectUri, ?string $origin): string
    {
        $config = $this->configuredProvider($provider);
        $redirectUri = $this->validatedFrontendRedirectUri($frontendRedirectUri, $origin);
        $state = Str::random(64);

        Cache::put($this->stateKey($state), [
            'provider' => $provider,
            'tenant_id' => $this->tenantContext->id(),
            'frontend_redirect_uri' => $redirectUri,
        ], self::STATE_TTL_SECONDS);

        return $config['authorize_url'] . '?' . http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $this->callbackUrl($provider),
            'response_type' => 'code',
            'scope' => implode(' ', $config['scopes']),
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    public function callback(string $provider, string $code, string $state): string
    {
        $stateData = Cache::pull($this->stateKey($state));

        abort_if(! is_array($stateData) || ($stateData['provider'] ?? null) !== $provider, Response::HTTP_UNAUTHORIZED, __('user::auth.sso_state_invalid'));

        $config = $this->configuredProvider($provider);
        $token = $this->exchangeCodeForToken($provider, $config, $code);
        $profile = $this->fetchUserProfile($config, $token);
        $email = strtolower((string) ($profile['email'] ?? $profile['upn'] ?? ''));

        abort_if(blank($email), Response::HTTP_UNAUTHORIZED, __('user::auth.sso_email_missing'));
        abort_if(array_key_exists('email_verified', $profile) && ! (bool) $profile['email_verified'], Response::HTTP_UNAUTHORIZED, __('user::auth.sso_email_unverified'));

        $user = $this->resolveUser($email, $stateData['tenant_id'] ?? null);
        abort_if(! $user, Response::HTTP_UNAUTHORIZED, __('user::auth.sso_user_not_found'));

        $this->auth->validateUserStatus($user);

        $handoff = Str::random(72);
        Cache::put($this->handoffKey($handoff), [
            'user_id' => $user->getKey(),
            'provider' => $provider,
        ], self::HANDOFF_TTL_SECONDS);

        return $this->appendQuery($stateData['frontend_redirect_uri'], ['token' => $handoff]);
    }

    public function complete(string $handoffToken): array
    {
        $payload = Cache::pull($this->handoffKey($handoffToken));

        abort_if(! is_array($payload), Response::HTTP_UNAUTHORIZED, __('user::auth.sso_handoff_invalid'));

        $user = User::query()
            ->withoutGlobalActive()
            ->find($payload['user_id'] ?? null);

        $this->auth->validateUserStatus($user);

        return $this->auth->grantToken($user, false, 'SSO ' . Str::headline((string) ($payload['provider'] ?? 'Login')));
    }

    private function exchangeCodeForToken(string $provider, array $config, string $code): string
    {
        $response = Http::asForm()
            ->timeout(12)
            ->post($config['token_url'], [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->callbackUrl($provider),
            ]);

        abort_if(! $response->successful(), Response::HTTP_UNAUTHORIZED, __('user::auth.sso_token_failed'));

        $accessToken = $response->json('access_token');
        abort_if(blank($accessToken), Response::HTTP_UNAUTHORIZED, __('user::auth.sso_token_failed'));

        return (string) $accessToken;
    }

    private function fetchUserProfile(array $config, string $token): array
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(12)
            ->get($config['userinfo_url']);

        abort_if(! $response->successful(), Response::HTTP_UNAUTHORIZED, __('user::auth.sso_profile_failed'));

        return $response->json() ?: [];
    }

    private function resolveUser(string $email, int|string|null $tenantId): ?User
    {
        return User::query()
            ->withoutGlobalActive()
            ->withTrashed()
            ->where('email', $email)
            ->when(
                filled($tenantId),
                fn ($query) => $query->where('tenant_id', $tenantId),
                fn ($query) => $query->whereNull('tenant_id')
            )
            ->first();
    }

    private function providerEnabled(array $provider): bool
    {
        return (bool) ($provider['enabled'] ?? false)
            && filled($provider['client_id'] ?? null)
            && filled($provider['client_secret'] ?? null);
    }

    private function normalizeProviderConfig(array $config): array
    {
        $tenant = $config['tenant'] ?? 'common';

        foreach (['authorize_url', 'token_url'] as $key) {
            $config[$key] = str_replace('{tenant}', $tenant, $config[$key]);
        }

        $config['scopes'] = Arr::wrap($config['scopes'] ?? ['openid', 'email', 'profile']);

        return $config;
    }

    private function validatedFrontendRedirectUri(string $redirectUri, ?string $origin): string
    {
        $redirectHost = parse_url($redirectUri, PHP_URL_HOST);
        $originHost = $origin ? parse_url($origin, PHP_URL_HOST) : null;

        abort_if(blank($redirectHost), Response::HTTP_UNPROCESSABLE_ENTITY, __('user::auth.sso_redirect_invalid'));
        abort_if(filled($originHost) && $originHost !== $redirectHost, Response::HTTP_UNPROCESSABLE_ENTITY, __('user::auth.sso_redirect_invalid'));

        return $redirectUri;
    }

    private function callbackUrl(string $provider): string
    {
        return url("/api/v1/auth/sso/{$provider}/callback");
    }

    private function appendQuery(string $url, array $query): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    private function stateKey(string $state): string
    {
        return 'auth:sso:state:' . hash('sha256', $state);
    }

    private function handoffKey(string $token): string
    {
        return 'auth:sso:handoff:' . hash('sha256', $token);
    }
}
