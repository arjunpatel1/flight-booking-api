<?php

namespace Modules\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Order\Enums\DeliveryStatus;
use Modules\Order\Models\OrderDelivery;
use Modules\Order\Support\TenantStaffRecipients;
use Modules\User\Models\User;
use Throwable;

final class EscalateUnassignedDeliveries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue((string) config('delivery.queue', 'delivery'));
    }

    public function handle(NotificationServiceInterface $notifications): void
    {
        OrderDelivery::query()->withoutGlobalScopes()
            ->where('status', DeliveryStatus::RiderSearching->value)
            ->whereNull('rider_assigned_at')->whereNull('assignment_escalated_at')
            ->whereNotNull('assignment_deadline_at')->where('assignment_deadline_at', '<=', now())
            ->orderBy('assignment_deadline_at')->limit(100)->get()->each(function (OrderDelivery $delivery) use ($notifications): void {
                try {
                    $claimed = DB::transaction(function () use ($delivery): ?OrderDelivery {
                        $locked = OrderDelivery::query()->withoutGlobalScopes()->whereKey($delivery->id)->lockForUpdate()->first();
                        if (! $locked || $locked->status !== DeliveryStatus::RiderSearching || $locked->rider_assigned_at || $locked->assignment_escalated_at) {
                            return null;
                        }
                        $history = $locked->quote_history ?? [];
                        $history[] = ['event' => 'rider_assignment_overdue', 'deadline_at' => $locked->assignment_deadline_at?->toIso8601String(), 'at' => now()->toIso8601String()];
                        $locked->forceFill(['assignment_escalated_at' => now(), 'failure_code' => 'RIDER_ASSIGNMENT_TIMEOUT', 'failure_reason' => 'No rider was assigned before the configured deadline.', 'quote_history' => $history])->save();

                        return $locked;
                    });
                    if (! $claimed) {
                        return;
                    }
                    $order = $claimed->order()->withoutGlobalScopes()->first();
                    if (! $order) {
                        return;
                    }
                    TenantStaffRecipients::withAnyPermission((int) $claimed->tenant_id, ['admin.orders.show', 'admin.delivery_settings.edit'])
                        ->each(fn (User $user) => $notifications->create([
                            'title' => "Driver assignment overdue · Order #{$order->order_number}",
                            'message' => 'The delivery network has not assigned a driver within the configured time. Review the live status, cancel and recreate delivery, or cancel the full order.',
                            'type' => 'delivery_assignment_overdue', 'severity' => 'error', 'icon' => 'tabler-clock-exclamation', 'color' => 'error',
                            'action_url' => '/admin/orders/'.$order->id.'/show',
                            'payload' => ['order_reference' => $order->reference_no, 'delivery_id' => $claimed->id, 'deadline_at' => $claimed->assignment_deadline_at?->toIso8601String()],
                        ], $user));
                    SendStaffDeliveryActionAlert::dispatch(
                        (int) $order->id,
                        (int) $claimed->tenant_id,
                        'rider-assignment-overdue',
                        'No rider was assigned within 15 minutes. Review the delivery and take action.',
                        'staff_delivery_rider_unassigned',
                    );
                } catch (Throwable $exception) {
                    Log::warning('Unable to escalate overdue rider assignment.', ['delivery_id' => $delivery->id, 'error' => mb_substr($exception->getMessage(), 0, 300)]);
                }
            });
    }
}
