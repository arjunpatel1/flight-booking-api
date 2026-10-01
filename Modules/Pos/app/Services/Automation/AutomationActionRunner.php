<?php

namespace Modules\Pos\Services\Automation;

use Illuminate\Support\Facades\DB;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintJob;
use Modules\Printer\Services\PrintJob\PrintJobServiceInterface;

/**
 * Executes the reversible side of automations and classifies action safety.
 *
 * Only reversible operations run here (notify_*); risky operations are classified
 * 'approval_required' (must go through the existing Manager Approval flow) or
 * 'suggestion_only' and are NEVER executed by the automation endpoint.
 */
class AutomationActionRunner
{
    /** Reversible / idempotent-safe actions safe to execute directly. */
    private const SAFE = ['notify_waiter', 'notify_kitchen', 'retry_print_queue'];

    /** Risky actions that must use the existing Manager Approval infrastructure. */
    private const APPROVAL_REQUIRED = [
        'mark_available', 'release_reservation', 'serve_ready_order',
        'auto_cancel', 'auto_refund', 'auto_void', 'close_session',
        'cash_adjustment', 'table_merge',
    ];

    public function __construct(
        private readonly NotificationServiceInterface $notifications,
        private readonly PrintJobServiceInterface $printJobs,
    ) {
    }

    public function classify(string $actionKey): string
    {
        return match (true) {
            in_array($actionKey, self::SAFE, true) => 'safe',
            in_array($actionKey, self::APPROVAL_REQUIRED, true) => 'approval_required',
            default => 'suggestion_only',
        };
    }

    public function isSafe(string $actionKey): bool
    {
        return $this->classify($actionKey) === 'safe';
    }

    /**
     * Run a safe reversible action. Returns ['result' => [...], 'rollback' => [...]|null].
     *
     * @param array<string,mixed> $evidence
     * @return array{result:array<string,mixed>,rollback:?array<string,mixed>}
     */
    public function run(string $actionKey, array $evidence, ?int $executorId, ?int $branchId = null): array
    {
        return match ($actionKey) {
            'notify_waiter' => $this->notify(
                targetUserId: $this->resolveWaiter($evidence) ?? $executorId,
                title: 'Action needed on a table',
                message: (string) ($evidence['message'] ?? 'A table needs your attention.'),
                evidence: $evidence,
            ),
            'notify_kitchen' => $this->notify(
                targetUserId: $executorId,
                title: 'Kitchen attention required',
                message: (string) ($evidence['message'] ?? 'Kitchen needs attention.'),
                evidence: $evidence,
            ),
            'retry_print_queue' => $this->retryPrintQueue($branchId),
            default => ['result' => ['status' => 'unsupported'], 'rollback' => null],
        };
    }

    /**
     * Reverse a previously-executed action. Returns true when reversed.
     *
     * @param array<string,mixed> $rollbackPayload
     */
    public function rollback(array $rollbackPayload): bool
    {
        return match ($rollbackPayload['type'] ?? null) {
            'withdraw_notification' => $this->withdrawNotification((int) ($rollbackPayload['notification_id'] ?? 0)),
            default => false,
        };
    }

    /**
     * @param array<string,mixed> $evidence
     * @return array{result:array<string,mixed>,rollback:?array<string,mixed>}
     */
    private function notify(?int $targetUserId, string $title, string $message, array $evidence): array
    {
        $notification = $this->notifications->create([
            'target_user_id' => $targetUserId,
            'title' => $title,
            'message' => $message,
            'type' => 'automation',
            'severity' => 'info',
            'payload' => ['source' => 'automation', 'evidence' => $evidence],
        ]);

        return [
            'result' => ['status' => 'notified', 'notification_id' => $notification->id, 'target_user_id' => $targetUserId],
            'rollback' => ['type' => 'withdraw_notification', 'notification_id' => $notification->id],
        ];
    }

    /**
     * Re-queue recent failed print jobs for the branch (printer recovery).
     * Idempotent/safe — retrying an already-failed job has no destructive effect;
     * no rollback (a print cannot be un-sent).
     *
     * @return array{result:array<string,mixed>,rollback:null}
     */
    private function retryPrintQueue(?int $branchId): array
    {
        $ids = PrintJob::query()
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v))
            ->where('status', PrintJobStatus::Failed->value)
            ->where('created_at', '>=', now()->subHours(2))
            ->orderBy('created_at')
            ->limit(25)
            ->pluck('id');

        $retried = 0;
        foreach ($ids as $id) {
            try {
                $this->printJobs->retry((string) $id);
                $retried++;
            } catch (\Throwable) {
                // Skip individual jobs that can't be retried; keep going.
            }
        }

        return ['result' => ['status' => 'retried', 'count' => $retried], 'rollback' => null];
    }

    private function withdrawNotification(int $notificationId): bool
    {
        if ($notificationId <= 0) {
            return false;
        }

        return (bool) Notification::query()->whereKey($notificationId)->delete();
    }

    /**
     * @param array<string,mixed> $evidence
     */
    private function resolveWaiter(array $evidence): ?int
    {
        $orderId = $evidence['order_id'] ?? null;
        if (! $orderId) {
            return null;
        }

        $waiterId = DB::table('orders')->where('id', $orderId)->value('waiter_id');

        return $waiterId ? (int) $waiterId : null;
    }
}
