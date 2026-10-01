<?php

namespace Modules\User\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Saas\Models\Tenant;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;

class TenantHandoffService
{
    public function __construct(private readonly AuthServiceInterface $auth)
    {
    }

    public function issue(Tenant $tenant, ?string $frontendUrl = null, array $context = []): array
    {
        abort_unless($tenant->is_active, Response::HTTP_UNPROCESSABLE_ENTITY, 'Tenant is not active.');

        $user = User::query()
            ->withoutGlobalActive()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [
                DefaultRole::Admin->value,
                DefaultRole::EnterpriseAdmin->value,
                DefaultRole::AdminBranch->value,
            ]))
            ->orderByRaw("CASE WHEN email = ? THEN 0 ELSE 1 END", [$tenant->contact_email])
            ->oldest('id')
            ->first();

        abort_if(! $user, Response::HTTP_NOT_FOUND, 'No active tenant admin user is available for login.');

        $token = Str::random(72);
        $handoffTtlSeconds = max(30, (int) config('saas.support.handoff_ttl_seconds', 60));
        Cache::put($this->handoffKey($token), [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'issued_at' => now()->toIso8601String(),
            'support_mode' => true,
            'actor_id' => $context['actor_id'] ?? null,
            'reason' => $context['reason'] ?? null,
        ], $handoffTtlSeconds);

        return [
            'token' => $token,
            'expires_in' => $handoffTtlSeconds,
            'login_url' => $this->handoffUrl($tenant, $token, $frontendUrl),
        ];
    }

    public function complete(string $token): array
    {
        $payload = Cache::pull($this->handoffKey($token));

        abort_if(! is_array($payload), Response::HTTP_UNAUTHORIZED, 'Tenant login link is invalid or expired.');

        $user = User::query()
            ->withoutGlobalActive()
            ->where('tenant_id', $payload['tenant_id'] ?? null)
            ->find($payload['user_id'] ?? null);

        $this->auth->validateUserStatus($user);

        $auth = $this->auth->grantToken(
            $user,
            false,
            'SaaS Support Mode',
            max(5, (int) config('saas.support.session_minutes', 30))
        );

        return [
            ...$auth,
            'support_mode' => [
                'enabled' => (bool) ($payload['support_mode'] ?? false),
                'tenant_id' => (int) ($payload['tenant_id'] ?? 0),
                'actor_id' => $payload['actor_id'] ?? null,
                'expires_at' => $auth['expires_at'] ?? null,
            ],
        ];
    }

    private function handoffUrl(Tenant $tenant, string $token, ?string $frontendUrl): string
    {
        // The one-time support token must only be delivered to the tenant's
        // registered host. Never trust a request-supplied or stale settings URL
        // here: it could place a tenant session on the platform domain or leak
        // the handoff token to another origin.
        $registeredDomain = trim((string) $tenant->domain);
        abort_if($registeredDomain === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'Tenant domain is not configured.');
        $baseUrl = preg_match('#^https?://#i', $registeredDomain)
            ? $registeredDomain
            : 'https://' . $registeredDomain;

        return rtrim($baseUrl, '/') . '/auth/tenant-handoff?' . http_build_query(['token' => $token]);
    }

    private function handoffKey(string $token): string
    {
        return 'auth:tenant-handoff:' . hash('sha256', $token);
    }
}
