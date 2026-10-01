<?php

namespace Modules\Voice\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Voice\Services\VoiceAnnouncementService;
use Modules\Voice\Models\VoiceSetting;

class CheckDelayedOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];

    public function __construct()
    {
        $this->onQueue('voice');
    }

    public function middleware(): array
    {
        // Prevent concurrent runs: if this job is still processing, skip the next dispatch.
        return [(new WithoutOverlapping('check-delayed-orders'))->dontReleaseLock()];
    }

    public function handle(VoiceAnnouncementService $voiceService, ?NotificationServiceInterface $notificationService = null): void
    {
        try {
            $notificationService ??= app(NotificationServiceInterface::class);

            // Get all branches with voice enabled
            $branches = VoiceSetting::where('voice_enabled', true)
                ->whereNotNull('branch_id')
                ->pluck('branch_id');

            foreach ($branches as $branchId) {
                $this->checkBranchDelayedOrders($branchId, $voiceService, $notificationService);
            }

            Log::info('CheckDelayedOrdersJob completed successfully');
        } catch (\Exception $e) {
            Log::error('CheckDelayedOrdersJob failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function checkBranchDelayedOrders(
        int $branchId,
        VoiceAnnouncementService $voiceService,
        NotificationServiceInterface $notificationService
    ): void
    {
        // Get voice settings for this branch
        $voiceSetting = VoiceSetting::where('branch_id', $branchId)->first();
        if (!$voiceSetting) {
            return;
        }

        $thresholds = $this->delayThresholds($voiceSetting);
        $minimumThreshold = min($thresholds);

        Order::query()
            ->with(['table:id,name', 'waiter:id,name'])
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                OrderStatus::Pending->value,
                OrderStatus::Confirmed->value,
                OrderStatus::Preparing->value,
            ])
            ->where('created_at', '<=', now()->subMinutes($minimumThreshold))
            ->chunkById(100, function ($orders) use ($thresholds, $voiceService, $branchId, $notificationService) {
                foreach ($orders as $order) {
                    $delayMinutes = $order->created_at?->diffInMinutes(now()) ?? 0;

                    foreach ($thresholds as $thresholdMinutes) {
                        if ($delayMinutes >= $thresholdMinutes) {
                            $this->triggerDelayAlert(
                                $order,
                                $thresholdMinutes,
                                $delayMinutes,
                                $voiceService,
                                $notificationService,
                                $branchId
                            );
                        }
                    }
                }
            });
    }

    private function triggerDelayAlert(
        Order $order,
        int $delayThresholdMinutes,
        int $delayMinutes,
        VoiceAnnouncementService $voiceService,
        NotificationServiceInterface $notificationService,
        int $branchId
    ): void
    {
        $eventType = "OrderDelayed{$delayThresholdMinutes}";
        $lock = Cache::lock("voice:delayed-order:{$order->id}:{$delayThresholdMinutes}", 30);

        if (!$lock->get()) {
            Log::info("Delay alert skipped for order {$order->id}: lock already held");
            return;
        }

        try {
            $delayAlertExists = DB::table('voice_history')
                ->where('order_id', $order->id)
                ->where('event_type', $eventType)
                ->exists();

            if ($delayAlertExists) {
                return;
            }

            // Get table number
            $tableNumber = $order->table?->name ?? 'Unknown';

            // Trigger voice announcement
            $voiceService->triggerTemplateAnnouncement(
                $branchId,
                $order->id,
                'OrderDelayed',
                $eventType,
                [
                    'TableNumber' => $tableNumber,
                    'DelayMinutes' => $delayMinutes,
                ]
            );

            $this->notifyWaiterDelayedOrder(
                $order,
                $notificationService,
                $tableNumber,
                $delayMinutes,
                $delayThresholdMinutes,
                $eventType
            );

            Log::info("Delay alert triggered for order {$order->id}, table {$tableNumber}, delay {$delayMinutes} minutes, threshold {$delayThresholdMinutes}");
        } catch (\Exception $e) {
            Log::error("Failed to trigger delay alert for order {$order->id}: " . $e->getMessage());
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function delayThresholds(VoiceSetting $voiceSetting): array
    {
        $thresholds = [15, 20, 30];
        $configured = (int) ($voiceSetting->delay_threshold_minutes ?? 0);

        if ($configured > 0 && !in_array($configured, $thresholds, true)) {
            $thresholds[] = $configured;
        }

        sort($thresholds);

        return array_values(array_unique($thresholds));
    }

    private function notifyWaiterDelayedOrder(
        Order $order,
        NotificationServiceInterface $notificationService,
        string $tableNumber,
        int $delayMinutes,
        int $thresholdMinutes,
        string $eventType
    ): void {
        if (!$order->waiter) {
            return;
        }

        $exists = Notification::query()
            ->where('target_user_id', $order->waiter->id)
            ->where('type', 'order_delayed')
            ->where('payload->order_id', $order->id)
            ->where('payload->threshold_minutes', $thresholdMinutes)
            ->exists();

        if ($exists) {
            return;
        }

        $orderNumber = $order->order_number ?: $order->reference_no ?: $order->id;

        $notificationService->create([
            'title' => __('notification::notifications.order_delayed.title', [
                'table' => $tableNumber,
            ]),
            'message' => __('notification::notifications.order_delayed.message', [
                'order' => $orderNumber,
                'minutes' => $delayMinutes,
            ]),
            'type' => 'order_delayed',
            'severity' => NotificationSeverity::Warning->value,
            'icon' => 'tabler-alert-triangle',
            'color' => 'warning',
            'payload' => [
                'order_id' => $order->id,
                'reference_no' => $order->reference_no,
                'order_number' => $order->order_number,
                'table_id' => $order->table_id,
                'table_name' => $tableNumber,
                'delay_minutes' => $delayMinutes,
                'threshold_minutes' => $thresholdMinutes,
                'event_type' => $eventType,
            ],
        ], $order->waiter);
    }
}
