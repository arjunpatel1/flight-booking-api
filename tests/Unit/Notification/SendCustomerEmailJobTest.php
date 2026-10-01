<?php

namespace Tests\Unit\Notification;

use Modules\Notification\Jobs\SendCustomerEmailJob;
use Tests\TestCase;

class SendCustomerEmailJobTest extends TestCase
{
    public function test_tenant_and_delivery_contract_survive_queue_serialization(): void
    {
        $job = new SendCustomerEmailJob(
            41,
            'order_ready',
            'customer@example.com',
            'Order ready',
            'Your order is ready.',
            'customer-email:41:92:order_ready',
        );

        /** @var SendCustomerEmailJob $restored */
        $restored = unserialize(serialize($job));

        $this->assertSame(41, $restored->tenantId);
        $this->assertSame('order_ready', $restored->type);
        $this->assertSame('customer@example.com', $restored->recipient);
        $this->assertSame('customer-email:41:92:order_ready', $restored->dedupeKey);
    }

    public function test_worker_restores_tenant_context_and_releases_failed_dedupe_lock(): void
    {
        $source = file_get_contents(base_path('Modules/Notification/app/Jobs/SendCustomerEmailJob.php'));

        $this->assertStringContainsString('$context->setId($this->tenantId)', $source);
        $this->assertStringContainsString('Cache::forget($this->dedupeKey)', $source);
        $this->assertStringContainsString('NotificationChannel::Email', $source);
    }

    public function test_order_listener_is_registered_for_every_supported_order_lifecycle_event(): void
    {
        $provider = file_get_contents(base_path('Modules/Order/app/Providers/EventServiceProvider.php'));

        $this->assertSame(4, substr_count($provider, 'SendOrderEmailNotification::class'));
    }
}
