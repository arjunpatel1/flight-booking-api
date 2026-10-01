<?php

namespace Modules\Notification\Services\MarketingAutomation;

use Illuminate\Support\Facades\Schema;
use Modules\Notification\Jobs\BulkSendWhatsAppMessageJob;
use Modules\Notification\Models\WhatsAppLog;
use Modules\Support\Exceptions\DomainException;

class BirthdayOfferService
{
    public function queue(
        string $template = 'birthday_offer',
        ?string $couponCode = null,
        bool $force = false,
    ): array {
        if (!(bool) setting('whatsapp_marketing_campaigns_enabled', false)) {
            throw new DomainException(__('notification::notifications.marketing_campaigns_disabled'));
        }

        if (!(bool) setting('crm_birthday_offer_automation_enabled', false)) {
            throw new DomainException(__('notification::notifications.birthday_offer_automation_disabled'));
        }

        if (!Schema::hasColumn('users', 'date_of_birth')) {
            throw new DomainException(__('notification::notifications.customer_birthday_field_missing'));
        }

        $campaignKey = 'birthday_customers:' . now()->toDateString();
        $campaignId = 'auto-' . $campaignKey;

        if (!$force && $this->alreadyQueuedToday($campaignId)) {
            throw new DomainException(__('notification::notifications.birthday_offer_automation_already_queued'));
        }

        $parameters = array_filter([
            'coupon_code' => $couponCode ?: setting('crm_birthday_offer_coupon_code'),
            'offer_title' => setting('crm_birthday_offer_title'),
        ], fn($value) => filled($value));

        BulkSendWhatsAppMessageJob::dispatch('birthday_customers', $template, $parameters, null, null, $campaignId);

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
