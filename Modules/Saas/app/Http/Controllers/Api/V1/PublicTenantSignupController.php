<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Services\Onboarding\OnboardingRequestService;
use Modules\Saas\Services\Provisioning\SaasProvisioningService;
use Modules\Support\ApiResponse;

class PublicTenantSignupController extends Controller
{
    public function store(Request $request, SaasProvisioningService $service): JsonResponse
    {
        abort_unless(config('saas.self_service.enabled'), 403, 'Self-service onboarding is not enabled.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('tenants', 'slug')],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'admin_name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'gst_number' => ['nullable', 'string', 'max:40'],
            'logo_url' => ['nullable', 'url', 'max:1000'],
            'primary_color' => ['nullable', 'string', 'max:20'],
            'secondary_color' => ['nullable', 'string', 'max:20'],
            'captcha_token' => [config('saas.self_service.captcha_required') ? 'required' : 'nullable', 'string', 'max:2048'],
            'terms' => ['accepted'],
            'website' => ['nullable', 'prohibited'],
        ]);

        $this->verifyCaptcha($validated['captcha_token'] ?? null);

        $payload = [
            ...$validated,
            'plan' => config('saas.self_service.default_plan', 'starter'),
            'trial_days' => config('saas.self_service.trial_days', config('saas.billing.trial_days', 90)),
        ];

        /*
         * SELF_SERVICE_MODE decides what a signup does.
         *
         *   legacy   — provision immediately (the original behaviour, default)
         *   pipeline — register an onboarding request and let the approval
         *              pipeline decide, so self-service signups appear in the
         *              operator queue and on the lifecycle board like every
         *              other customer.
         *
         * Legacy stays the default and its response is byte-for-byte unchanged,
         * so the existing public onboarding page keeps working untouched.
         * Pipeline responses carry `mode` and `onboarding_request` instead of a
         * tenant, because in that mode no tenant exists yet.
         */
        if ($this->pipelineMode()) {
            $request = app(OnboardingRequestService::class)->create([
                ...$payload,
                'restaurant_name' => $validated['name'],
                'source' => 'self_service',
                'requires_payment' => (bool) config('saas.self_service.requires_payment', false),
            ]);

            return ApiResponse::created([
                'mode' => 'pipeline',
                'onboarding_request' => [
                    'uuid' => $request->uuid,
                    'status' => $request->status->value,
                    'approval_mode' => $request->approval_mode,
                    'payment_status' => $request->payment_status,
                ],
            ], 'Signup received. We will confirm your restaurant shortly.');
        }

        $result = $service->provision($payload);

        return ApiResponse::created([
            'mode' => 'legacy',
            'tenant' => $result['tenant']->only(['id', 'name', 'slug', 'domain']),
            'admin' => $result['admin']->only(['id', 'name', 'email']),
            'subscription' => $result['subscription']->fresh('plan'),
            'provisioning' => [
                'uuid' => $result['provisioning_run']->uuid,
                'status' => $result['provisioning_run']->status,
                'progress' => $result['provisioning_run']->progress,
            ],
            'urls' => $result['urls'],
        ], 'Restaurant signup completed. Verify email and complete billing before production activation.');
    }

    /**
     * Unrecognised values fall back to legacy: a typo in an env var must never
     * silently change what a paying signup does.
     */
    private function pipelineMode(): bool
    {
        return strtolower((string) config('saas.self_service.mode', 'legacy')) === 'pipeline';
    }

    private function verifyCaptcha(?string $token): void
    {
        if (! config('saas.self_service.captcha_required')) {
            return;
        }

        $secret = config('saas.self_service.captcha_secret');
        abort_unless($secret, 422, 'Captcha verification is not configured.');

        $response = Http::asForm()
            ->timeout(5)
            ->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
            ]);

        abort_unless((bool) ($response->json('success') ?? false), 422, 'Captcha verification failed.');
    }
}
