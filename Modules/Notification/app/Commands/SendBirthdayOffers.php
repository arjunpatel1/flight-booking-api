<?php

namespace Modules\Notification\Commands;

use Illuminate\Console\Command;
use Modules\Notification\Services\MarketingAutomation\BirthdayOfferService;
use Modules\Support\Exceptions\DomainException;

class SendBirthdayOffers extends Command
{
    protected $signature = 'whatsapp:send-birthday-offers
                            {--template=birthday_offer : WhatsApp template ID}
                            {--coupon= : Coupon code variable}
                            {--force : Send even if this automation already ran today}';

    protected $description = 'Queue WhatsApp birthday offers for customers with birthdays today.';

    public function handle(BirthdayOfferService $service): int
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

        $this->info("Birthday offer campaign queued: {$result['campaign_id']}");

        return self::SUCCESS;
    }
}
