<?php

namespace Modules\Notification\Services\MarketingAutomation;

use Modules\Notification\Jobs\BulkSendWhatsAppMessageJob;
use Modules\Notification\Models\WhatsAppLog;
use Modules\Support\Exceptions\DomainException;

class InactiveCustomerOfferService
{
    public function queue(
        string $template = 'inactive_customer_offer',
        ?string $couponCode = null,
        ?string $validUntil = null,
        bool $force = false,
    ): array {
        if (!(bool) setting('whatsapp_marketing_campaigns_enabled', false)) {
            throw new DomainException(__('notification::notifications.marketing_campaigns_disabled'));
        }

        if (!(bool) setting('crm_inactive_customer_automation_enabled', false)) {
            throw new DomainException(__('notification::notifications.inactive_customer_automation_disabled'));
        }

        $campaignKey = 'inactive_customers:' . now()->toDateString();
        $campaignId = 'auto-' . $campaignKey;

        if (!$force && $this->alreadyQueuedToday($campaignId)) {
            throw new DomainException(__('notification::notifications.inactive_customer_automation_already_queued'));
        }

        $parameters = array_filter([
            'coupon_code' => $couponCode ?: setting('crm_inactive_customer_coupon_code'),
            'valid_until' => $validUntil ?: setting('crm_inactive_customer_offer_valid_until'),
            'offer_title' => setting('crm_inactive_customer_offer_title'),
        ], fn($value) => filled($value));

        BulkSendWhatsAppMessageJob::dispatch('inactive_customers', $template, $parameters, null, null, $campaignId);

        return [
            'campaign_id' => $campaignId,
            'template' => $template,
            'parameters' => $parameters,
        ];
    }

    private function alreadyQueuedToday(string $campaignId): bool
    {
        $user = auth()->user();
        if ($user?->tenantId() === null) {
            throw new \LogicException('A tenant context is required to deduplicate restaurant campaigns.');
        }

        return WhatsAppLog::query()
            ->forTenant($user->tenantId(), $user->branchId())
            ->where('request_payload->campaign_id', $campaignId)
            ->whereDate('created_at', today())
            ->exists();
    }
}
