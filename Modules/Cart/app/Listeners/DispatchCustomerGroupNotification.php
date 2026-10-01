<?php

namespace Modules\Cart\Listeners;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Cart\Events\CustomerGroupCartUpdated;
use Modules\Cart\Models\CustomerGroupParticipant;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Notification\Services\NotificationDispatcherService;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\User\Models\User;

class DispatchCustomerGroupNotification implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public array $backoff = [10, 30, 90];

    public function __construct(
        private readonly NotificationServiceInterface $notifications,
        private readonly NotificationDispatcherService $dispatcher,
    ) {
    }

    public function handle(CustomerGroupCartUpdated $event): void
    {
        $definition = $this->definition($event->eventName);
        if ($definition === null) {
            return;
        }

        $group = $event->group;
        $recipientIds = $this->recipientIds($event);

        User::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $group->tenant_id)
            ->whereIn('id', $recipientIds)
            ->where('is_active', true)
            ->each(function (User $customer) use ($definition, $event, $group): void {
                $notification = $this->notifications->create([
                    'title' => $definition['title'],
                    'message' => $definition['message'],
                    'type' => 'customer_group_order',
                    'severity' => NotificationSeverity::Info->value,
                    'action_url' => '/customer-app/group',
                    'payload' => [
                        'group_reference' => (string) $group->id,
                        'event_type' => $event->eventName,
                        'tenant_id' => (int) $group->tenant_id,
                    ],
                ], $customer);

                $this->dispatcher->dispatch(
                    'customer_group_order',
                    (string) $customer->id,
                    [
                        'notification_id' => (string) $notification->id,
                        'group_reference' => (string) $group->id,
                        'event_type' => $event->eventName,
                        'tenant_id' => (int) $group->tenant_id,
                        'title' => $definition['title'],
                        'message' => $definition['message'],
                    ],
                    [NotificationChannel::Push],
                );
            });
    }

    private function recipientIds(CustomerGroupCartUpdated $event): array
    {
        $group = $event->group;
        $query = CustomerGroupParticipant::query()
            ->where('tenant_id', $group->tenant_id)
            ->where('group_cart_id', $group->id);

        if ($event->eventName === 'participant_joined') {
            return [(int) $group->host_customer_id];
        }

        if ($event->eventName === 'participant_removed') {
            $participantId = (int) ($event->metadata['participant_id'] ?? 0);

            return $participantId > 0
                ? $query->whereKey($participantId)->pluck('customer_id')->map(fn ($id) => (int) $id)->all()
                : [];
        }

        return $query->where('status', 'active')
            ->pluck('customer_id')
            ->reject(fn ($id) => (int) $id === (int) $event->actorCustomerId)
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function definition(string $event): ?array
    {
        return match ($event) {
            'participant_joined' => ['title' => 'Someone joined your group order', 'message' => 'Open the group to see the latest participants.'],
            'participant_removed' => ['title' => 'You were removed from a group order', 'message' => 'This shared cart is no longer available to your account.'],
            'locked' => ['title' => 'Group order is ready for checkout', 'message' => 'The host locked the shared cart. Items can no longer be changed.'],
            'completed' => ['title' => 'Group order placed', 'message' => 'The host completed checkout. Open the app for the latest order status.'],
            'cancelled' => ['title' => 'Group order cancelled', 'message' => 'The host cancelled this shared order.'],
            'expired' => ['title' => 'Group order expired', 'message' => 'This shared order expired before checkout.'],
            default => null,
        };
    }
}
