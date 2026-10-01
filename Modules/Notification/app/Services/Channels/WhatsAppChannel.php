<?php

namespace Modules\Notification\Services\Channels;

use Modules\Notification\Enums\NotificationStatus;
use Modules\Notification\Models\WhatsAppLog;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Services\Setting\SettingServiceInterface;

class WhatsAppChannel implements NotificationChannelInterface
{
    public function __construct(private readonly WhatsAppProviderFactory $factory)
    {
    }

    public function send(string $recipient, array $payload): array
    {
        $context = app(TenantContext::class);
        $settings = app(SettingServiceInterface::class);
        $previous = $context->id();
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            throw new \LogicException('A tenant-bound WhatsApp notification is required.');
        }
        $context->setId($tenantId);
        $settings->refreshSettingBinding();
        try {
            if (! filter_var(setting('whatsapp_enabled', false), FILTER_VALIDATE_BOOL)) {
                throw new \RuntimeException('WhatsApp messaging is disabled for this restaurant.');
            }
            $template = (string) ($payload['template'] ?? 'default');
            $parameters = (array) ($payload['parameters'] ?? []);
            $log = WhatsAppLog::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => filled($payload['branch_id'] ?? null) ? (int) $payload['branch_id'] : null,
                'provider' => setting('whatsapp_provider') ?: 'msg91',
                'recipient' => $recipient,
                'template' => $template,
                'status' => NotificationStatus::Processing,
                'request_payload' => [
                    'parameter_keys' => array_keys($parameters),
                    'audience' => $payload['audience'] ?? 'transactional',
                    'campaign_id' => $payload['campaign_id'] ?? null,
                ],
            ]);
            try {
                $response = $this->factory->make()->sendTemplate($recipient, $template, $parameters);
                $log->update([
                    'status' => NotificationStatus::Sent,
                    'response_payload' => $response,
                    'sent_at' => now(),
                ]);

                return $response;
            } catch (\Throwable $exception) {
                $log->update([
                    'status' => NotificationStatus::Failed,
                    'error_message' => $exception->getMessage(),
                ]);
                throw $exception;
            }
        } finally {
            $context->setId($previous);
            $settings->refreshSettingBinding();
        }
    }
}
