<?php

namespace Modules\WhatsAppCenter\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppCenter\Data\NormalizedWebhookMessage;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use Modules\WhatsAppCenter\Models\WhatsAppWebhookEvent;
use Throwable;

/**
 * Makes rejected provider callbacks visible to administrators.
 *
 * Public webhook responses stay generic. The precise reason is stored on the
 * provider profile and, for signed events, as a failed webhook log entry for
 * the restaurant that owns the number, so "No webhook events found" is never
 * the only symptom of a misconfiguration.
 */
final class WhatsAppWebhookRejectionRecorder
{
    private const REASONS = [
        'WHATSAPP_ASSIGNMENT_INVALID' => 'This WhatsApp number is not assigned to an active restaurant, or its assignment is suspended. Activate the assignment in SaaS → WhatsApp Ordering.',
        'WHATSAPP_INTEGRATION_DISABLED' => 'WhatsApp ordering is switched off for this number. Enable ordering on the restaurant assignment.',
        'WHATSAPP_TENANT_DISABLED' => 'The restaurant that owns this number is inactive.',
        'WHATSAPP_BRANCH_UNAVAILABLE' => 'None of the branches allowed for this number is active. Update the allowed branches.',
        'WHATSAPP_ACCOUNT_NOT_FOUND' => 'The provider sent a number ID that does not match an active number on this profile. Check the WABA / phone number ID.',
    ];

    public static function reason(string $errorCode): string
    {
        return self::REASONS[$errorCode] ?? 'The webhook was rejected by NexDine.';
    }

    /**
     * Unsigned requests may come from anyone, so only a rate-limited
     * profile-level hint is stored; no event row is created for them.
     */
    public function invalidSignature(WhatsAppProviderProfile $profile): void
    {
        if (! Cache::add('whatsapp:webhook-signature-rejected:'.$profile->id, true, now()->addMinutes(5))) {
            return;
        }

        $this->remember($profile, 'The last provider webhook had an invalid signature. Save the same webhook secret in the provider portal and in NexDine.');
    }

    /** Codes that describe the number owner's own setup, safe to show to that restaurant. */
    private const TENANT_VISIBLE = [
        'WHATSAPP_ASSIGNMENT_INVALID', 'WHATSAPP_INTEGRATION_DISABLED', 'WHATSAPP_TENANT_DISABLED', 'WHATSAPP_BRANCH_UNAVAILABLE',
    ];

    public function contextRejected(WhatsAppProviderProfile $profile, NormalizedWebhookMessage $message, string $errorCode, string $correlationId): void
    {
        $reason = self::reason($errorCode);
        $this->remember($profile, $reason);
        if (! in_array($errorCode, self::TENANT_VISIBLE, true)) {
            return;
        }

        try {
            $tenantId = WhatsAppTenantAssignment::query()->withoutGlobalTenant()
                ->where('provider_profile_id', $profile->id)
                ->whereHas('phoneNumber', fn ($query) => $query->where('provider_phone_id', $message->providerPhoneId))
                ->latest('id')->value('tenant_id');
            // Never attribute an event to a restaurant other than the owner
            // of a restaurant-owned profile.
            if (! $tenantId || ($profile->tenant_id !== null && (int) $profile->tenant_id !== (int) $tenantId)) {
                return;
            }

            WhatsAppWebhookEvent::query()->withoutGlobalTenant()->firstOrCreate(
                // A distinct key keeps a later, successful retry of the same
                // provider event processable once the setup is corrected.
                ['provider_profile_id' => $profile->id, 'provider_event_id' => mb_substr('rejected:'.$message->providerEventId, 0, 191)],
                [
                    'tenant_id' => $tenantId,
                    'event_type' => 'rejected',
                    'payload_hash' => hash('sha256', $message->providerEventId.'|'.$errorCode),
                    'payload' => [
                        'type' => $message->type,
                        'provider_phone_id' => $message->providerPhoneId,
                        'provider_event_id' => $message->providerEventId,
                        'error_code' => $errorCode,
                        'correlation_id' => $correlationId,
                    ],
                    'status' => 'failed',
                    'processed_at' => now(),
                    'error' => $reason,
                ],
            );
        } catch (Throwable $exception) {
            // Diagnostics must never turn a clean rejection into a 500.
            Log::warning('Could not record rejected WhatsApp webhook.', [
                'provider_profile_id' => $profile->id, 'error_code' => $errorCode, 'exception' => $exception::class,
            ]);
        }
    }

    private function remember(WhatsAppProviderProfile $profile, string $reason): void
    {
        try {
            $profile->forceFill(['last_error' => $reason])->saveQuietly();
        } catch (Throwable) {
            // Best effort only.
        }
    }
}
