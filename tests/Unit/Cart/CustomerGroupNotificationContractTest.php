<?php

namespace Tests\Unit\Cart;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Cart\Events\CustomerGroupCartUpdated;
use Modules\Cart\Listeners\DispatchCustomerGroupNotification;
use Modules\Cart\Models\CustomerGroupCart;
use Modules\Cart\Providers\EventServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CustomerGroupNotificationContractTest extends TestCase
{
    public function test_group_event_broadcast_exposes_only_non_sensitive_state(): void
    {
        $group = new CustomerGroupCart();
        $group->forceFill([
            'id' => '00000000-0000-4000-8000-000000000001',
            'tenant_id' => 16,
            'status' => 'locked',
            'version' => 7,
        ]);
        $event = new CustomerGroupCartUpdated($group, 'locked', 99, ['private_note' => 'never broadcast']);

        $this->assertSame(
            'private-customer-group.tenant.16.group.00000000-0000-4000-8000-000000000001',
            $event->broadcastOn()[0]->name,
        );
        $this->assertSame([
            'group_id' => '00000000-0000-4000-8000-000000000001',
            'event' => 'locked',
            'status' => 'locked',
            'version' => 7,
        ], $event->broadcastWith());
    }

    public function test_push_listener_is_after_commit_and_registered_once(): void
    {
        $this->assertContains(
            ShouldQueueAfterCommit::class,
            class_implements(DispatchCustomerGroupNotification::class),
        );

        $listeners = (new ReflectionClass(EventServiceProvider::class))->getDefaultProperties()['listen'];
        $this->assertSame(
            [DispatchCustomerGroupNotification::class],
            $listeners[CustomerGroupCartUpdated::class],
        );
    }

    public function test_push_policy_avoids_minor_cart_mutation_spam(): void
    {
        $reflection = new ReflectionClass(DispatchCustomerGroupNotification::class);
        $listener = $reflection->newInstanceWithoutConstructor();
        $definition = $reflection->getMethod('definition');

        foreach (['participant_joined', 'participant_removed', 'locked', 'completed', 'cancelled', 'expired'] as $event) {
            $this->assertIsArray($definition->invoke($listener, $event));
        }
        foreach (['created', 'item_added', 'item_updated', 'item_removed', 'coupon_applied', 'coupon_removed'] as $event) {
            $this->assertNull($definition->invoke($listener, $event));
        }
    }
}
