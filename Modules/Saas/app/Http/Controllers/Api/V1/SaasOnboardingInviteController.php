<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Services\NotificationDispatcherService;
use Modules\Saas\Models\SaasOnboardingInvite;
use Modules\Saas\Services\Provisioning\SaasProvisioningService;
use Modules\Support\ApiResponse;

class SaasOnboardingInviteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $query = SaasOnboardingInvite::query()->with('tenant:id,name,slug');

        if ($status === 'expired') {
            $query->where('status', 'pending')->where('expires_at', '<=', now());
        } elseif (in_array($status, ['pending', 'completed', 'revoked'], true)) {
            $query->where('status', $status);
            if ($status === 'pending') {
                $query->where('expires_at', '>', now());
            }
        }

        $invites = $query->latest()->paginate(min(100, max(10, $request->integer('per_page', 25))));
        $invites->getCollection()->transform(fn (SaasOnboardingInvite $invite) => $this->adminPayload($invite));

        return ApiResponse::success($invites);
    }

    public function create(Request $request, NotificationDispatcherService $notifications): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'plan' => ['nullable', 'string', 'max:120'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'message' => ['nullable', 'string', 'max:1000'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', Rule::in(['email', 'whatsapp'])],
        ]);

        $token = Str::random(64);
        $invite = SaasOnboardingInvite::query()->create([
            'uuid' => (string) Str::uuid(),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'token_hash' => hash('sha256', $token),
            'payload' => [
                'name' => $data['name'],
                'plan' => $data['plan'] ?? config('saas.self_service.default_plan', 'starter'),
                'trial_days' => $data['trial_days'] ?? config('saas.billing.trial_days', 90),
            ],
            'expires_at' => now()->addDays((int) config('saas.self_service.invite_days', 7)),
            'created_by' => $request->user()?->id,
        ]);

        $delivery = $this->dispatchInvite($invite, $token, $notifications, $data['channels'] ?? ['email'], $data['message'] ?? null);
        $delivered = collect($delivery['deliveries'])->contains(fn (array $item) => $item['status'] === 'sent');

        return ApiResponse::created([
            'invite' => $invite->only(['uuid', 'email', 'phone', 'status', 'expires_at']),
            ...$delivery,
        ], $delivered
            ? 'Onboarding link sent.'
            : 'Setup link created, but delivery failed. Copy the secure link or retry after fixing the mail provider.');
    }

    public function resend(Request $request, SaasOnboardingInvite $invite, NotificationDispatcherService $notifications): JsonResponse
    {
        abort_if($invite->status === 'completed', 409, 'A completed onboarding invitation cannot be resent.');

        $data = $request->validate([
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', Rule::in(['email', 'whatsapp'])],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $token = Str::random(64);
        $invite->forceFill([
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addDays((int) config('saas.self_service.invite_days', 7)),
        ])->save();

        $delivery = $this->dispatchInvite($invite, $token, $notifications, $data['channels'] ?? ['email'], $data['message'] ?? null);
        $delivered = collect($delivery['deliveries'])->contains(fn (array $item) => $item['status'] === 'sent');

        return ApiResponse::success([
            'invite' => $this->adminPayload($invite->refresh()),
            ...$delivery,
        ], $delivered
            ? 'Onboarding invitation resent.'
            : 'Invitation renewed, but delivery failed. Copy the secure link or retry after fixing the mail provider.');
    }

    public function revoke(Request $request, SaasOnboardingInvite $invite): JsonResponse
    {
        abort_if($invite->status === 'completed', 409, 'A completed onboarding invitation cannot be revoked.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $invite->forceFill([
            'status' => 'revoked',
            'payload' => [...($invite->payload ?? []), 'revoked_reason' => $data['reason'], 'revoked_at' => now()->toIso8601String()],
        ])->save();

        return ApiResponse::success(['invite' => $this->adminPayload($invite->refresh())], 'Onboarding invitation revoked.');
    }

    public function show(string $token): JsonResponse
    {
        $invite = $this->find($token);
        abort_unless($invite->isUsable(), 410, 'This onboarding link has expired or was already used.');

        return ApiResponse::success([
            'name' => $invite->payload['name'] ?? '',
            'email' => $invite->email,
            'phone' => $invite->phone,
            'plan' => $invite->payload['plan'] ?? 'starter',
            'trial_days' => $invite->payload['trial_days'] ?? config('saas.billing.trial_days', 90),
            'expires_at' => $invite->expires_at,
        ]);
    }

    public function complete(Request $request, string $token, SaasProvisioningService $service): JsonResponse
    {
        $invite = $this->find($token);
        abort_unless($invite->isUsable(), 410, 'This onboarding link has expired or was already used.');

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('tenants', 'slug')],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')],
            'phone' => ['nullable', 'string', 'max:30'],
            'admin_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'gst_number' => ['nullable', 'string', 'max:40'],
            'logo_url' => ['nullable', 'url', 'max:1000'],
            'primary_color' => ['nullable', 'string', 'max:20'],
            'secondary_color' => ['nullable', 'string', 'max:20'],
            'terms' => ['accepted'],
        ]);

        $result = $service->provision([
            ...$validated,
            'name' => $invite->payload['name'] ?? $validated['name'] ?? abort(422, 'Restaurant name is missing from this onboarding link.'),
            'email' => $invite->email,
            'plan' => $invite->payload['plan'] ?? 'starter',
            'trial_days' => $invite->payload['trial_days'] ?? config('saas.billing.trial_days', 90),
        ]);

        $invite->forceFill([
            'tenant_id' => $result['tenant']->id,
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        $channels = setting('notifications_email_enabled', true) ? [NotificationChannel::Email] : [];
        if (setting('whatsapp_enabled', false) && setting('notifications_whatsapp_enabled', false) && $invite->phone) {
            $channels[] = NotificationChannel::WhatsApp;
        }

        $credentials = [
            'title' => 'Your NexDine login details',
            'message' => 'Your restaurant workspace is ready. Use the login details below.',
            'restaurant_name' => $result['tenant']->name,
            'email' => $invite->email,
            'password' => $validated['password'],
            'login_url' => $result['urls']['restaurant_url'] ?? null,
            'template' => 'saas_onboarding_credentials',
            'parameters' => [$result['tenant']->name, $invite->email, $validated['password'], $result['urls']['restaurant_url'] ?? ''],
        ];
        $notifications = app(\Modules\Notification\Services\NotificationDispatcherService::class);
        foreach ($channels as $channel) {
            $recipient = $channel === NotificationChannel::WhatsApp ? $invite->phone : $invite->email;
            $notifications->dispatch('saas_onboarding_credentials', $recipient, $credentials, [$channel]);
        }

        return ApiResponse::created([
            'tenant' => $result['tenant']->only(['id', 'name', 'slug', 'domain']),
            'admin' => $result['admin']->only(['id', 'name', 'email']),
            'subscription' => $result['subscription']->fresh('plan'),
            'provisioning' => $result['provisioning_run']->only(['uuid', 'status', 'progress']),
            'urls' => $result['urls'],
        ], 'Restaurant setup completed. Login details will be delivered through the configured channels.');
    }

    private function find(string $token): SaasOnboardingInvite
    {
        return SaasOnboardingInvite::query()->where('token_hash', hash('sha256', trim($token)))->firstOrFail();
    }

    private function dispatchInvite(
        SaasOnboardingInvite $invite,
        string $token,
        NotificationDispatcherService $notifications,
        array $channels,
        ?string $message = null
    ): array {
        $url = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/onboarding?invite='.$token;
        $restaurantName = $invite->payload['name'] ?? 'your restaurant';
        $payload = [
            'title' => 'Complete your NexDine restaurant setup',
            'message' => $message ?? 'Use the secure link to complete your restaurant details and activate NexDine.',
            'setup_url' => $url,
            'brand_name' => config('saas.company_mail.brand_name', 'NexDine'),
            'logo_url' => config('saas.company_mail.logo_url'),
            'restaurant_name' => $restaurantName,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'template' => 'saas_onboarding_invite',
            'parameters' => [$restaurantName, $url],
        ];

        $deliveries = collect($channels)
            ->map(fn (string $channel) => $channel === 'whatsapp' ? NotificationChannel::WhatsApp : NotificationChannel::Email)
            ->flatMap(function (NotificationChannel $channel) use ($invite, $notifications, $payload): array {
                $recipient = $channel === NotificationChannel::WhatsApp
                    ? ($invite->phone ?: abort(422, 'Phone is required for WhatsApp.'))
                    : $invite->email;
                return $notifications->dispatch('saas_onboarding_invite', $recipient, $payload, [$channel]);
            })
            ->map(fn ($log) => [
                'id' => $log->id,
                'channel' => $log->channel->value,
                'status' => $log->status->value,
                'error' => $log->status->value === 'failed' ? $log->error_message : null,
            ])
            ->values()
            ->all();

        return ['url' => $url, 'deliveries' => $deliveries];
    }

    private function adminPayload(SaasOnboardingInvite $invite): array
    {
        $status = $invite->status === 'pending' && $invite->expires_at?->isPast() ? 'expired' : $invite->status;

        return [
            'id' => $invite->id,
            'uuid' => $invite->uuid,
            'restaurant_name' => $invite->payload['name'] ?? null,
            'email' => $invite->email,
            'phone' => $invite->phone,
            'plan' => $invite->payload['plan'] ?? null,
            'trial_days' => $invite->payload['trial_days'] ?? null,
            'status' => $status,
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'completed_at' => $invite->completed_at?->toIso8601String(),
            'tenant' => $invite->tenant?->only(['id', 'name', 'slug']),
            'created_at' => $invite->created_at?->toIso8601String(),
        ];
    }
}
