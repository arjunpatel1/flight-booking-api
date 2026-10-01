<?php

namespace Modules\Pos\Services\PosViewer\Concerns;

use Darryldecode\Cart\Exceptions\InvalidConditionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Cart\Facades\Cart;
use Modules\Category\Models\Category;
use Modules\Discount\Models\Discount;
use Modules\Menu\Models\Menu;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderProductStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Order\Support\OrderActionPolicy;
use Modules\Order\Support\KitchenSla;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Pos\Transformers\Api\V1\Pos\PosCategoryResource;
use Modules\Pos\Transformers\Api\V1\Pos\PosProductResource;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintJob;
use Modules\Product\Models\Product;
use Modules\SeatingPlan\Enums\ReservationStatus;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableReservation;
use Modules\Support\ActionPolicy;
use Modules\Support\Money;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;
use Modules\Core\Intelligence\ContextEngine;
use Modules\Core\Intelligence\DecisionEngine;
use Modules\Core\Intelligence\HealthScore;
use Modules\Core\Intelligence\MemoryScore;
use Modules\Core\Intelligence\PredictionEngine;

trait HandlesAssistantRecoveryCards
{
    private function readyOrderCards(?int $branchId, User $user): array
    {
        return $this->assistantOrderQuery($branchId, $user)
            ->where('orders.status', OrderStatus::Ready->value)
            ->latest('orders.updated_at')
            ->limit(5)
            ->get()
            ->map(fn(Order $order) => $this->orderCard(
                order: $order,
                user: $user,
                type: 'kitchen_ready',
                severity: 'critical',
                title: 'Order ready',
                message: $this->orderLocationLabel($order) . ' is ready for service.',
                action: 'open_order',
                actionName: 'view',
            ))
            ->all();
    }

    private function delayedOrderCards(?int $branchId, User $user): array
    {
        $sla = app(KitchenSla::class);

        return $this->assistantOrderQuery($branchId, $user)
            ->whereIn('orders.status', $sla->activeStatusValues())
            ->where('orders.created_at', '<=', $sla->delayedCutoff())
            ->oldest('orders.created_at')
            ->limit(5)
            ->get()
            ->map(function (Order $order) use ($user, $sla) {
                $payload = $sla->payload($order);
                $delayMinutes = (int) ($payload['delay_minutes'] ?? 0);

                return $this->orderCard(
                    order: $order,
                    user: $user,
                    type: 'order_delayed',
                    severity: ($payload['severity'] ?? 'warning') === 'critical' ? 'critical' : 'warning',
                    title: 'Order delayed',
                    message: $this->orderLocationLabel($order) . " is delayed by {$delayMinutes} minute(s).",
                    action: 'open_order',
                    actionName: 'view',
                );
            })
            ->all();
    }

    private function paymentPendingCards(?int $branchId, User $user): array
    {
        return $this->assistantOrderQuery($branchId, $user)
            ->whereIn('orders.status', [
                OrderStatus::Ready->value,
                OrderStatus::Served->value,
                OrderStatus::Completed->value,
            ])
            ->whereIn('orders.payment_status', [
                OrderPaymentStatus::Unpaid->value,
                OrderPaymentStatus::PartiallyPaid->value,
            ])
            ->latest('orders.updated_at')
            ->limit(5)
            ->get()
            ->map(fn(Order $order) => $this->orderCard(
                order: $order,
                user: $user,
                type: 'payment_pending',
                severity: 'warning',
                title: 'Payment pending',
                message: $this->orderLocationLabel($order) . ' still has a pending bill.',
                action: 'receive_payment',
                actionName: 'receive_payment',
            ))
            ->all();
    }

    private function printQueueCards(?int $branchId, User $user): array
    {
        if (! Schema::hasTable('print_jobs')) {
            return [];
        }

        $failed = PrintJob::query()
            ->withOutGlobalBranchPermission()
            ->when($branchId, fn(Builder $query) => $query->where('branch_id', $branchId))
            ->where('status', PrintJobStatus::Failed->value)
            ->where('updated_at', '>=', now()->subHours(8))
            ->latest('updated_at')
            ->limit(3)
            ->get();

        $pendingCount = PrintJob::query()
            ->withOutGlobalBranchPermission()
            ->when($branchId, fn(Builder $query) => $query->where('branch_id', $branchId))
            ->where('status', PrintJobStatus::Pending->value)
            ->where('created_at', '<=', now()->subMinutes(3))
            ->count();

        $cards = $failed->map(fn(PrintJob $job) => $this->assistantCard(
            id: "print-failed-{$job->id}",
            type: 'print_failed',
            severity: 'critical',
            title: 'Print failed',
            message: $job->error_message ?: 'A print job failed and needs attention.',
            entityType: 'print_job',
            entityId: (string) $job->id,
            action: 'retry_print',
            policy: $this->assistantPolicy(
                allowed: $user->can('admin.print_jobs.retry'),
                reason: 'Permission denied.',
                loadingKey: 'print_retry',
                permissions: ['admin.print_jobs.retry']
            ),
            createdAt: $job->updated_at?->toISOString(),
        ))->all();

        if ($pendingCount > 0) {
            $cards[] = $this->assistantCard(
                id: 'print-pending',
                type: 'print_pending',
                severity: 'warning',
                title: 'Print queue waiting',
                message: "{$pendingCount} print job(s) have been pending for more than 3 minutes.",
                entityType: 'print_queue',
                entityId: null,
                action: 'open_print_queue',
                policy: $this->assistantPolicy(
                    allowed: $user->can('admin.print_jobs.index'),
                    reason: 'Permission denied.',
                    loadingKey: 'print_queue',
                    permissions: ['admin.print_jobs.index']
                ),
            );
        }

        return $cards;
    }

    private function terminalRecoveryCards(?int $branchId, User $user): array
    {
        if (! Schema::hasTable('pos_terminal_devices')) {
            return [];
        }

        $device = PosTerminalDevice::query()
            ->withOutGlobalBranchPermission()
            ->when($branchId, fn(Builder $query) => $query->where('branch_id', $branchId))
            ->when(
                $user->hasRole(DefaultRole::Waiter->value),
                fn(Builder $query) => $query->where('created_by', $user->id)
            )
            ->latest('last_seen_at')
            ->first();

        if (! $device) {
            return [];
        }

        $offlineAfterSeconds = (int) config('pos.fleet.offline_after_seconds', 90);
        $payload = $device->toStatusPayload($offlineAfterSeconds, config('pos.fleet.min_app_version'));
        $status = $payload['status'] ?? $device->status;
        $queueCount = (int) ($payload['queue_count'] ?? ((int) $device->local_queue_count + (int) $device->server_queue_count));
        $health = $payload['health'] ?? ['status' => 'ok', 'issues' => []];
        $firstIssue = $health['issues'][0] ?? null;
        $title = match ($firstIssue['code'] ?? null) {
            'crashes' => 'Terminal crash reported',
            'battery_low' => 'Terminal battery low',
            'upgrade_required' => 'Terminal app update needed',
            'debug_build' => 'Debug build detected',
            default => $status === 'offline' ? 'Terminal offline' : ($status === 'error' ? 'Terminal error' : 'Sync pending'),
        };

        if (($health['status'] ?? 'ok') === 'ok' && $status === 'online' && $queueCount === 0) {
            return [];
        }

        return [$this->assistantCard(
            id: "terminal-{$device->id}",
            type: $status === 'error' ? 'terminal_error' : ($status === 'offline' ? 'terminal_offline' : 'terminal_syncing'),
            severity: ($health['status'] ?? null) === 'critical' || $status === 'error' ? 'critical' : 'warning',
            title: $title,
            message: $queueCount > 0
                ? "{$queueCount} local/server item(s) still need recovery."
                : ($firstIssue['message'] ?? 'This terminal needs connectivity or sync attention.'),
            entityType: 'terminal_device',
            entityId: (string) $device->id,
            action: 'open_recovery_dashboard',
            policy: $this->assistantPolicy(
                allowed: $user->can('admin.pos_terminal_devices.index') || $user->can('admin.pos.index'),
                reason: 'Permission denied.',
                loadingKey: 'recovery_dashboard',
                permissions: ['admin.pos_terminal_devices.index', 'admin.pos.index']
            ),
            createdAt: $device->last_seen_at?->toISOString(),
        )];
    }

    private function reservationCards(?int $branchId, User $user): array
    {
        if (! Schema::hasTable('table_reservations')) {
            return [];
        }

        return TableReservation::query()
            ->withOutGlobalBranchPermission()
            ->when($branchId, fn(Builder $query) => $query->where('branch_id', $branchId))
            ->whereDate('reservation_date', today())
            ->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value])
            ->whereTime('reservation_time', '>=', now()->format('H:i:s'))
            ->whereTime('reservation_time', '<=', now()->addMinutes(45)->format('H:i:s'))
            ->orderBy('reservation_time')
            ->limit(3)
            ->get()
            ->map(fn(TableReservation $reservation) => $this->assistantCard(
                id: "reservation-{$reservation->id}",
                type: 'reservation_soon',
                severity: 'info',
                title: 'Reservation arriving soon',
                message: trim(($reservation->customer_name ?: 'Guest') . ' at ' . (string) $reservation->reservation_time),
                entityType: 'reservation',
                entityId: (string) $reservation->id,
                tableId: $reservation->table_id,
                orderId: null,
                action: 'open_reservation',
                policy: $this->assistantPolicy(
                    allowed: $user->can('admin.reservations.index'),
                    reason: 'Permission denied.',
                    loadingKey: 'reservation',
                    permissions: ['admin.reservations.index']
                ),
                createdAt: $reservation->created_at?->toISOString(),
                expiresAt: now()->addMinutes(45)->toISOString(),
            ))
            ->all();
    }

    private function cashSessionCards(?int $branchId, User $user): array
    {
        if (! Schema::hasTable('pos_sessions') || ! $branchId) {
            return [];
        }

        $hasOpenSession = PosSession::query()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $branchId)
            ->where('status', PosSessionStatus::Open->value)
            ->exists();

        if ($hasOpenSession) {
            return [];
        }

        return [$this->assistantCard(
            id: 'cash-session-missing',
            type: 'cash_session_missing',
            severity: 'critical',
            title: 'POS session missing',
            message: 'Cash movement, collection, and payments may be blocked until a session is opened.',
            entityType: 'pos_session',
            entityId: null,
            action: 'open_pos_session',
            policy: $this->assistantPolicy(
                allowed: $user->can('admin.pos_sessions.open'),
                reason: 'Permission denied.',
                loadingKey: 'pos_session',
                permissions: ['admin.pos_sessions.open']
            ),
        )];
    }
}
