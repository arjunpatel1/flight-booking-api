<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Provisioning\TenantClientConfigService;
use Modules\Saas\Services\Workspace\ActivationKeyService;
use Modules\Saas\Services\Workspace\WaiterActivationChallengeService;
use Modules\Support\ApiResponse;

class SaasClientConfigController extends Controller
{
    public function show(string $slug, TenantClientConfigService $service): JsonResponse
    {
        // Runtime configuration is never exposed by a guessable tenant slug.
        // Devices must redeem a one-time challenge or use a temporary signed URL.
        abort(404);
    }

    public function signed(string $slug, TenantClientConfigService $service): JsonResponse
    {
        return $this->configuration($slug, $service);
    }

    private function configuration(string $slug, TenantClientConfigService $service): JsonResponse
    {
        $tenant = Tenant::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();
        abort_unless(app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class)->has($tenant, 'waiter_app'), 404);

        return ApiResponse::success($service->config($tenant));
    }

    public function activation(string $slug, TenantClientConfigService $service): JsonResponse
    {
        // Kept as a non-disclosing compatibility endpoint. Public slug-based
        // activation was replayable and bypassed tenant app entitlements.
        abort(404);
    }

    public function activationKey(
        Request $request,
        ActivationKeyService $keys,
        WaiterActivationChallengeService $challenges,
        TenantClientConfigService $service,
    ): JsonResponse {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
        ]);

        $normalisedKey = $keys->normalise($data['key']);
        $attemptKey = 'saas-activation-key:'.hash('sha256', $normalisedKey);

        // The route limiter protects the API by source IP. This second,
        // secret-specific limiter prevents a single activation credential from
        // being replayed rapidly through multiple addresses.
        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            return ApiResponse::errors(
                errors: ['key' => [__('saas::workspace.activation.too_many_attempts')]],
                message: __('saas::workspace.activation.too_many_attempts'),
                code: 429,
            );
        }

        RateLimiter::hit($attemptKey, 15 * 60);
        $tenant = $keys->isWellFormed($normalisedKey)
            ? $challenges->consume($normalisedKey, 'code', $request)
            : null;

        // Use one response for malformed, unknown, inactive and mismatched
        // credentials. Public callers must not be able to enumerate tenant
        // existence from status codes or error wording.
        if (! $tenant || ! app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class)->has($tenant, 'waiter_app')) {
            return ApiResponse::errors(
                errors: ['key' => [__('saas::workspace.activation.invalid')]],
                message: __('saas::workspace.activation.invalid'),
                code: 422,
            );
        }

        return ApiResponse::success($service->config($tenant));
    }

    public function redeem(
        Request $request,
        WaiterActivationChallengeService $challenges,
        TenantClientConfigService $service,
    ): JsonResponse {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $tenant = $challenges->consume($data['token'], 'token', $request);

        if (! $tenant || ! app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class)->has($tenant, 'waiter_app')) {
            return ApiResponse::errors(errors: ['token' => ['Activation is invalid, expired, or already used.']], message: 'Activation is invalid, expired, or already used.', code: 422);
        }

        return ApiResponse::success($service->config($tenant));
    }
}
