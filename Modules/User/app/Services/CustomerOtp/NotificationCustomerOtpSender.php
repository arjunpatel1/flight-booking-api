<?php

namespace Modules\User\Services\CustomerOtp;

use Modules\Notification\Services\Channels\EmailChannel;
use Modules\Notification\Services\Channels\WhatsAppChannel;
use Modules\Saas\Models\CustomerAppSetting;
use Modules\Saas\Models\Tenant;
use RuntimeException;

class NotificationCustomerOtpSender implements CustomerOtpSender
{
    public function __construct(
        private readonly WhatsAppChannel $whatsApp,
        private readonly EmailChannel $email,
    ) {}

    public function send(string $channel, string $recipient, string $otp, int $ttlMinutes, int $tenantId, string $restaurantName): ?string
    {
        $otpTemplate = collect(setting('whatsapp_templates') ?: [])
            ->first(fn ($template) => is_array($template)
                && ($template['event'] ?? null) === 'customer_otp'
                && ($template['is_active'] ?? true));

        $result = match ($channel) {
            'whatsapp' => $this->whatsApp->send($recipient, [
                // Keep the authoritative menu tenant attached to the delivery
                // boundary. Long-running workers and public routes must not
                // accidentally resolve provider credentials from a stale
                // global settings binding.
                'tenant_id' => $tenantId,
                'audience' => 'customer_otp',
                'campaign_id' => 'customer-otp:'.hash('sha256', $recipient.'|'.$otp),
                'otp_code' => $otp,
                'template' => $otpTemplate['template_id'] ?? $otpTemplate['id']
                    ?? config('services.customer_otp.whatsapp_template', 'customer_login_otp'),
                'parameters' => [
                    'otp' => $otp,
                    'button_code' => $otp,
                ],
            ]),
            'email' => $this->email->send($recipient, $this->emailOtpPayload($tenantId, $restaurantName, $otp, $ttlMinutes)),
            default => throw new RuntimeException('The selected verification channel is unavailable.'),
        };

        $providerResponse = is_array($result['response'] ?? null) ? $result['response'] : [];
        $accepted = ($result['queued'] ?? $result['sent'] ?? $result['success'] ?? false) === true
            && ($providerResponse === [] || ($providerResponse['success'] ?? true) === true)
            && (int) ($providerResponse['failedCount'] ?? 0) === 0;
        if (! $accepted) {
            throw new RuntimeException('Customer OTP delivery failed.');
        }

        $providerReference = $result['id']
            ?? $result['provider_message_id']
            ?? $providerResponse['messageId']
            ?? data_get($providerResponse, 'results.0.messageId');

        // A WhatsApp request must have a provider acknowledgement. Without it
        // the client must not display "Verification code sent".
        if ($channel === 'whatsapp' && blank($providerReference)) {
            throw new RuntimeException('Customer OTP provider did not acknowledge the message.');
        }

        return filled($providerReference) ? (string) $providerReference : null;
    }

    /** The restaurant controls wording only; security values are injected server-side. */
    private function emailOtpPayload(int $tenantId, string $restaurantName, string $otp, int $ttlMinutes): array
    {
        $settings = CustomerAppSetting::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first()?->settings ?? [];
        $tenantSettings = Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->first(['id', 'settings'])?->settings ?? [];
        $template = data_get($settings, 'email_templates.customer_otp', []);
        $subject = (string) data_get($template, 'subject', 'Your {{restaurant_name}} verification code');
        $body = (string) data_get($template, 'body', "Your verification code is {{otp}}. It expires in {{expiry_minutes}} minutes. Never share this code.");
        $variables = [
            '{{restaurant_name}}' => trim($restaurantName) ?: config('app.name'),
            '{{otp}}' => $otp,
            '{{expiry_minutes}}' => (string) $ttlMinutes,
        ];

        return [
            'subject' => strtr($subject, $variables),
            'body' => strtr($body, $variables),
            'restaurant_name' => $variables['{{restaurant_name}}'],
            'logo_url' => (string) (data_get($settings, 'branding.logo_url')
                ?? data_get($settings, 'logo_url')
                ?? data_get($tenantSettings, 'branding.logo_url')
                ?? data_get($tenantSettings, 'logo_url')
                ?? data_get($tenantSettings, 'restaurant_logo_url')
                ?? ''),
            'primary_color' => (string) (data_get($settings, 'branding.primary_color')
                ?? data_get($settings, 'primary_color')
                ?? data_get($tenantSettings, 'branding.primary_color')
                ?? data_get($tenantSettings, 'theme_primary_color')
                ?? '#F57C00'),
            'tenant_id' => $tenantId,
            'otp_code' => $otp,
        ];
    }
}
