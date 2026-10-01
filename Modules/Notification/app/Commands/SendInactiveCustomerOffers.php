<?php

namespace Modules\Notification\Commands;

use Illuminate\Console\Command;
use Modules\Notification\Services\MarketingAutomation\InactiveCustomerOfferService;
use Modules\Support\Exceptions\DomainException;

class SendInactiveCustomerOffers extends Command
{
    protected $signature = 'whatsapp:send-inactive-customer-offers
                            {--template=inactive_customer_offer : WhatsApp template ID}
                            {--coupon= : Coupon code variable}
                            {--valid-until= : Offer valid until variable}
                            {--force : Send even if this automation already ran today}';

    protected $description = 'Queue WhatsApp offers for inactive customers using the configured campaign audience.';

    public function handle(InactiveCustomerOfferService $service): int
    {
        try {
            $result = $service->queue(
                template: (string) $this->option('template'),
                couponCode: $this->option('coupon'),
                validUntil: $this->option('valid-until'),
                force: (bool) $this->option('force'),
            );
        } catch (DomainException $exception) {
            $this->warn($exception->getMessage());
            return self::SUCCESS;
        }

        $this->info("Inactive customer offer campaign queued: {$result['campaign_id']}");

        return self::SUCCESS;
    }
}
