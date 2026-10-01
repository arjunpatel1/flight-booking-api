<?php

namespace Modules\Notification\Commands;

use Illuminate\Console\Command;
use Modules\Notification\Services\MarketingAutomation\AnniversaryOfferService;
use Modules\Support\Exceptions\DomainException;

class SendAnniversaryOffers extends Command
{
    protected $signature = 'whatsapp:send-anniversary-offers
                            {--template=anniversary_offer : WhatsApp template ID}
                            {--coupon= : Coupon code variable}
                            {--force : Send even if this automation already ran today}';

    protected $description = 'Queue consented WhatsApp anniversary offers for customers with anniversaries today.';

    public function handle(AnniversaryOfferService $service): int
    {
        try {
            $result = $service->queue(
                template: (string) $this->option('template'),
                couponCode: $this->option('coupon'),
                force: (bool) $this->option('force'),
            );
        } catch (DomainException $exception) {
            $this->warn($exception->getMessage());
            return self::SUCCESS;
        }

        $this->info("Anniversary offer campaign queued: {$result['campaign_id']}");
        return self::SUCCESS;
    }
}
