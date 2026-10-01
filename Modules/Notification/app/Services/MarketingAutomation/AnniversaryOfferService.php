<?php

namespace Modules\Notification\Services\MarketingAutomation;

use Illuminate\Support\Facades\Schema;
use Modules\Notification\Jobs\BulkSendWhatsAppMessageJob;
use Modules\Notification\Models\WhatsAppLog;
use Modules\Support\Exceptions\DomainException;

class AnniversaryOfferService
{
    public function queue(
        string $template = 'anniversary_offer',
        ?string $couponCode = null,
        bool $force = false,
    ): array {
        if (!(bool) setting('whatsapp_marketing_campaigns_enabled', false)) {
            throw new DomainException(__('notification::notifications.marketing_campaigns_disabled'));
        }

        if (!(bool) setting('crm_anniversary_offer_automation_enabled', false)) {
            throw new DomainException(__('notification::notifications.anniversary_offer_automation_disabled'));
        }

        if (!Schema::hasColumn('users', 'anniversary_date')) {
            throw new DomainException(__('notification::notifications.customer_anniversary_field_missing'));
        }

        $actor = auth()->user();
        if ($actor?->tenantId() === null) {
            throw new \LogicException('A tenant context is required to queue anniversary campaigns.');
        }

        $campaignId = 'auto-anniversary_customers:'.now()->toDateString();
        $alreadyQueued = WhatsAppLog::query()
            ->forTenant($actor->tenantId(), $actor->branchId())
            ->where('request_payload->campaign_id', $campaignId)
            ->whereDate('created_at', today())
            ->exists();

        if (!$force && $alreadyQueued) {
            throw new DomainException(__('notification::notifications.anniversary_offer_automation_already_queued'));
        }

        $parameters = array_filter([
            'coupon_code' => $couponCode ?: setting('crm_anniversary_offer_coupon_code'),
            'offer_title' => setting('crm_anniversary_offer_title'),
        ], fn ($value) => filled($value));

        BulkSendWhatsAppMessageJob::dispatch(
            'anniversary_customers',
            $template,
            $parameters,
            campaignId: $campaignId,
        );

        return [
            'campaign_id' => $campaignId,
            'template' => $template,
            'parameters' => $parameters,
        ];
    }
}
