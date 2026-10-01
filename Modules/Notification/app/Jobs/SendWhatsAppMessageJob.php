<?php

namespace Modules\Notification\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Modules\Notification\Enums\NotificationStatus;
use Modules\Notification\Enums\WhatsAppProvider;
use Modules\Notification\Models\WhatsAppLog;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable;

    public readonly ?int $tenantId;

    public readonly ?int $branchId;

    public int $tries = 3;

    public int $backoff = 60; // 1 minute delay between retries

    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $recipient,
        public readonly string $template,
        public readonly array $parameters = [],
        public readonly array $metadata = [],
    ) {
        $user = auth()->user();
        $this->tenantId = isset($metadata['tenant_id']) ? (int) $metadata['tenant_id'] : $user?->tenantId();
        $this->branchId = isset($metadata['branch_id']) ? (int) $metadata['branch_id'] : $user?->branchId();
        $this->onQueue('whatsapp');
    }

    public function handle(WhatsAppProviderFactory $factory): void
    {
        if ($this->tenantId === null) {
            throw new \LogicException('A tenant-bound WhatsApp job is required.');
        }
        $context = app(TenantContext::class);
        $settings = app(SettingServiceInterface::class);
        $context->setId($this->tenantId);
        $settings->refreshSettingBinding();
        try {
            if ($this->branchId !== null && ! DB::table('branches')->where('id', $this->branchId)->where('tenant_id', $this->tenantId)->exists()) {
                throw new \LogicException('WhatsApp branch does not belong to the queued tenant.');
            }

            // Honour the master switch again when delayed jobs execute.
            if (! $this->whatsAppEnabled()) {
                return;
            }
            // A queued delivery message must respect switches changed before
            // the worker runs, including a disabled approved template.
            if (($this->metadata['audience'] ?? null) === 'delivery_tracking') {
                if (! filter_var(setting('whatsapp_delivery_alerts_enabled', false), FILTER_VALIDATE_BOOL)) return;
                $deliveryId = filter_var($this->metadata['delivery_id'] ?? null, FILTER_VALIDATE_INT);
                $deliveryStatus = $this->metadata['delivery_status'] ?? null;
                if (! $deliveryId || ! is_string($deliveryStatus) || $this->branchId === null
                    || ! DB::table('order_deliveries')->where('id', $deliveryId)
                        ->where('tenant_id', $this->tenantId)->where('branch_id', $this->branchId)
                        ->where('status', $deliveryStatus)->exists()) return;
                $deliveryTemplates = collect(setting('whatsapp_templates') ?: [])
                    ->filter(fn ($item) => is_array($item) && ($item['event'] ?? null) === 'delivery_update');
                if ($deliveryTemplates->isNotEmpty() && ! $deliveryTemplates->contains(fn (array $item) =>
                    ($item['is_active'] ?? true) && ($item['template_id'] ?? $item['id'] ?? null) === $this->template
                )) return;
            }

            $provider = $this->effectiveProvider();
            if (! $this->isProviderConfigured($provider)) {
                throw new \Exception("WhatsApp provider '{$provider}' is not properly configured. Please check API credentials.");
            }

            $log = WhatsAppLog::create([
                'tenant_id' => $this->tenantId,
                'branch_id' => $this->branchId,
                'provider' => $provider,
                'recipient' => $this->recipient,
                'template' => $this->template,
                'request_payload' => [
                    'parameter_keys' => array_keys($this->parameters),
                    'audience' => $this->metadata['audience'] ?? null,
                    'campaign_id' => $this->metadata['campaign_id'] ?? null,
                    'scheduled_at' => $this->metadata['scheduled_at'] ?? null,
                    'report_type' => $this->metadata['report_type'] ?? null,
                ],
                'retry_payload' => ['parameters' => $this->parameters],
                'status' => NotificationStatus::Processing,
            ]);
            try {
                // Queue delays must not consume the customer's five-minute
                // cancellation window before the message is sent.
                if (filled($this->parameters['cancel_token'] ?? null) && filled($this->parameters['order_id'] ?? null)) {
                    Cache::put(
                        'customer-order-cancel-link:'.hash('sha256', (string) $this->parameters['cancel_token']),
                        [
                            'reference' => (string) $this->parameters['order_id'],
                            'expires_at' => now()->addMinutes(5)->toIso8601String(),
                        ],
                        now()->addMinutes(5),
                    );
                }
                $response = $factory->make(WhatsAppProvider::from($provider))->sendTemplate($this->recipient, $this->template, $this->parameters);
                $log->update([
                    'status' => NotificationStatus::Sent,
                    'response_payload' => $response,
                    'retry_payload' => null,
                    'sent_at' => now(),
                ]);
            } catch (\Throwable $exception) {
                $errorMessage = ($this->metadata['audience'] ?? null) === 'staff_order_alert'
                    ? 'Staff alert provider failure; manual review required.'
                    : $exception->getMessage();
                $log->update([
                    'status' => NotificationStatus::Failed,
                    'error_message' => $errorMessage,
                ]);

                \Log::error('WhatsApp Send Failed', [
                    'tenant_id' => $this->tenantId,
                    'provider' => $provider,
                    'recipient_fingerprint' => hash('sha256', $this->recipient),
                    'template' => $this->template,
                    'attempt' => $this->attempts(),
                    'error' => $errorMessage,
                ]);

                throw $exception;
            }
        } finally {
            // Queue workers are long-lived. Never let one restaurant's
            // provider credentials/settings bleed into the next job.
            $context->clear();
            $settings->refreshSettingBinding();
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Final failure handling - could send notification to admin
        \Log::critical('WhatsApp Message Failed Permanently', [
            'tenant_id' => $this->tenantId,
            'recipient_fingerprint' => hash('sha256', $this->recipient),
            'template' => $this->template,
            'error' => $exception->getMessage(),
        ]);
    }

    private function whatsAppEnabled(): bool
    {
        $configured = setting('whatsapp_enabled');
        if ($configured !== null) return filter_var($configured, FILTER_VALIDATE_BOOL);

        return $this->managedAssignment() !== null;
    }

    private function effectiveProvider(): string
    {
        return $this->managedAssignment()?->profile?->provider
            ?: (setting('whatsapp_provider') ?: 'msg91');
    }

    private function managedAssignment(): ?WhatsAppTenantAssignment
    {
        return WhatsAppTenantAssignment::query()->withoutGlobalTenant()->with('profile')
            ->where('tenant_id', $this->tenantId)->where('is_active', true)
            ->whereNull('suspended_at')->latest('id')->first();
    }

    private function isProviderConfigured(string $provider): bool
    {
        if ($provider === 'nexmsg' && ($managed = $this->managedAssignment())?->profile?->provider === 'nexmsg') {
            return filled(data_get($managed->profile->credentials, 'account_id'))
                && filled(data_get($managed->profile->credentials, 'auth_key'));
        }

        return match ($provider) {
            'nexmsg' => filled(setting('whatsapp_nexmsg_account_id')) && filled(setting('whatsapp_nexmsg_auth_key')),
            'msg91' => (filled(setting('whatsapp_msg91_utility_auth_key')) && filled(setting('whatsapp_msg91_utility_integrated_number')))
                || (filled(setting('whatsapp_msg91_auth_key')) && filled(setting('whatsapp_msg91_integrated_number'))),
            'meta' => filled(setting('whatsapp_meta_access_token')) && filled(setting('whatsapp_meta_phone_number_id')),
            'twilio' => filled(setting('whatsapp_twilio_sid')) && filled(setting('whatsapp_twilio_auth_token')) && filled(setting('whatsapp_twilio_from')),
            default => false,
        };
    }
}
