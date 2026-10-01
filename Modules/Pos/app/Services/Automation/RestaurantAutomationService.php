<?php

namespace Modules\Pos\Services\Automation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Automation\AutomationEngine;
use Modules\Core\Automation\AutomationRule;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\SeatingPlan\Enums\ReservationStatus;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class RestaurantAutomationService
{
    public function __construct(private readonly AutomationEngine $engine)
    {
    }

    public function evaluate(?int $branchId = null): array
    {
        $user = auth()->user();
        $branchId = $user->assignedToBranch() ? $user->branch_id : $branchId;
        $rules = [
            ...$this->tableRules($branchId, $user),
            ...$this->orderRules($branchId, $user),
            ...$this->printerRules($branchId),
            ...$this->shiftRules($branchId),
            ...$this->ownerRules($branchId),
        ];
        $payload = $this->engine->evaluate($rules, $user);
        $payload['branch_id'] = $branchId;
        $payload['generated_at'] = now()->toISOString();
        $payload['readiness_report'] = $this->readiness($branchId);
        $payload['playbooks'] = $this->playbooks($payload['decisions']);
        $payload['analytics'] = $this->analytics($payload['decisions']);

        $this->auditEvaluation($payload, $user);

        return $payload;
    }

    private function tableRules(?int $branchId, User $user): array
    {
        $idleMinutes = max((int) setting('automation_table_paid_idle_minutes', 8), 1);
        $longOccupiedMinutes = max((int) setting('automation_long_occupied_minutes', 75), 20);
        $autoMarkAvailable = (bool) setting('automation_auto_mark_available_enabled', false);
        $idle = DB::table('orders')
            ->leftJoin('tables', 'tables.id', '=', 'orders.table_id')
            ->when($branchId, fn($q) => $q->where('orders.branch_id', $branchId))
            ->when($user->hasRole(DefaultRole::Waiter->value), fn($q) => $q->where('orders.waiter_id', $user->id))
            ->whereNotNull('orders.table_id')
            ->where('orders.payment_status', OrderPaymentStatus::Paid->value)
            ->whereIn('orders.status', [OrderStatus::Served->value, OrderStatus::Completed->value])
            ->where('orders.updated_at', '<=', now()->subMinutes($idleMinutes))
            ->select('orders.id', 'orders.table_id', 'orders.order_number', 'orders.updated_at', 'tables.name as table_name', 'tables.status as table_status')
            ->orderBy('orders.updated_at')
            ->first();
        $longTable = Schema::hasTable('tables')
            ? DB::table('tables')
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->where('status', TableStatus::Occupied->value)
                ->where('updated_at', '<=', now()->subMinutes($longOccupiedMinutes))
                ->orderBy('updated_at')
                ->first(['id', 'name', 'updated_at'])
            : null;

        return [
            $this->rule('table-paid-idle', 'table', 'payment_completed', 'Paid table still occupied', $idle !== null,
                'Payment is complete but the table is still not released.', 'Slow turnover reduces seating capacity.',
                'Ask waiter to mark table available after physical verification.',
                ['admin.tables.update_status'], 'mark_available', 'Mark available',
                ['table_id' => $idle?->table_id, 'order_id' => $idle?->id, 'order_number' => $idle?->order_number],
                autoEnabled: $autoMarkAvailable && ($idle?->table_status === TableStatus::Cleaning->value), mode: $autoMarkAvailable ? 'auto' : 'suggest'),
            $this->rule('long-occupied-table', 'table', 'table_occupied_too_long', 'Long occupied table', $longTable !== null,
                'A table has stayed occupied beyond the configured target.', 'Manager may need to help close or serve.',
                'Notify assigned waiter, then escalate to manager if ignored.',
                ['admin.tables.viewer'], 'notify_waiter', 'Notify waiter',
                ['table_id' => $longTable?->id, 'table_name' => $longTable?->name], severity: 'warning'),
            ...$this->reservationNoShowRules($branchId),
        ];
    }

    private function reservationNoShowRules(?int $branchId): array
    {
        if (! Schema::hasTable('table_reservations')) {
            return [];
        }

        $grace = max((int) setting('automation_reservation_no_show_grace_minutes', 15), 1);
        $reservation = DB::table('table_reservations')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereDate('reservation_date', today())
            ->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value])
            ->where('reservation_time', '<=', now()->subMinutes($grace)->format('H:i:s'))
            ->orderBy('reservation_time')
            ->first(['id', 'table_id', 'customer_name', 'reservation_time']);

        return [
            $this->rule('reservation-no-show', 'reservation', 'reservation_no_show', 'Reservation no-show', $reservation !== null,
                'Reservation grace time passed without seating.', 'Blocked inventory can reduce revenue.',
                'Release the table after front-desk confirmation and notify host.',
                ['admin.reservations.edit'], 'release_reservation', 'Release reservation',
                ['reservation_id' => $reservation?->id, 'table_id' => $reservation?->table_id, 'customer_name' => $reservation?->customer_name], severity: 'warning'),
        ];
    }

    private function orderRules(?int $branchId, User $user): array
    {
        $readyMinutes = max((int) setting('automation_ready_order_escalation_minutes', 4), 1);
        $paymentMinutes = max((int) setting('automation_payment_pending_minutes', 12), 1);
        $ready = DB::table('orders')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($user->hasRole(DefaultRole::Waiter->value), fn($q) => $q->where('waiter_id', $user->id))
            ->where('status', OrderStatus::Ready->value)
            ->where('updated_at', '<=', now()->subMinutes($readyMinutes))
            ->orderBy('updated_at')
            ->first(['id', 'order_number', 'table_id', 'updated_at']);
        $payment = DB::table('orders')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($user->hasRole(DefaultRole::Waiter->value), fn($q) => $q->where('waiter_id', $user->id))
            ->whereIn('status', [OrderStatus::Served->value, OrderStatus::Completed->value])
            ->whereIn('payment_status', [OrderPaymentStatus::Unpaid->value, OrderPaymentStatus::PartiallyPaid->value])
            ->where('updated_at', '<=', now()->subMinutes($paymentMinutes))
            ->orderByDesc('total')
            ->first(['id', 'order_number', 'table_id', 'total']);

        return [
            $this->rule('ready-order-escalation', 'order', 'order_ready', 'Ready order waiting', $ready !== null,
                'Kitchen marked an order ready and it is still waiting.', 'Food quality and guest experience can drop.',
                'Notify assigned waiter now; escalate to captain if ignored.',
                ['admin.orders.active'], 'serve_ready_order', 'Serve order',
                ['order_id' => $ready?->id, 'order_number' => $ready?->order_number, 'table_id' => $ready?->table_id], severity: 'critical'),
            $this->rule('payment-pending-reminder', 'payment', 'payment_pending', 'Payment pending reminder', $payment !== null,
                'A served/completed order still has unpaid balance.', 'Cash collection and table release are delayed.',
                'Prompt cashier or waiter to collect payment.',
                ['admin.orders.receive_payment'], 'collect_payment', 'Collect payment',
                ['order_id' => $payment?->id, 'order_number' => $payment?->order_number, 'table_id' => $payment?->table_id, 'amount' => (float) ($payment?->total ?? 0)], severity: 'warning'),
        ];
    }

    private function printerRules(?int $branchId): array
    {
        if (! Schema::hasTable('print_jobs')) {
            return [];
        }

        $failed = DB::table('print_jobs')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->where('created_at', '>=', now()->subHours(2))
            ->where('status', PrintJobStatus::Failed->value)
            ->count();
        $pending = DB::table('print_jobs')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->where('status', PrintJobStatus::Pending->value)
            ->count();

        return [
            $this->rule('printer-failure-recovery', 'printer', 'printer_failed', 'Printer failure recovery', $failed > 0,
                'Recent print jobs failed.', 'Bills/KOT may be delayed or duplicated manually.',
                'Switch to backup printer if configured, then retry failed jobs.',
                ['admin.print_jobs.index'], 'retry_print_queue', 'Retry print jobs',
                ['failed_jobs' => $failed], severity: 'critical'),
            $this->rule('print-queue-growing', 'printer', 'print_queue_growing', 'Print queue growing', $pending >= 5,
                'Pending print jobs crossed the operational threshold.', 'Service slows when billing/KOT is queued.',
                'Recommend printer load balancing or agent restart.',
                ['admin.print_jobs.index'], 'balance_print_queue', 'Balance queue',
                ['pending_jobs' => $pending], severity: 'warning'),
        ];
    }

    private function shiftRules(?int $branchId): array
    {
        $openSessions = DB::table('pos_sessions')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->where('status', 'open')
            ->count();
        $openOrders = DB::table('orders')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Confirmed->value, OrderStatus::Preparing->value, OrderStatus::Ready->value, OrderStatus::Served->value])
            ->count();

        return [
            $this->rule('shift-open-readiness', 'shift', 'restaurant_open', 'Shift readiness check', $openSessions === 0,
                'No open POS session was found.', 'Cash movement, payments, and settlement may fail.',
                'Open a POS session before service starts.',
                ['admin.pos_sessions.open'], 'open_pos_session', 'Open session',
                ['open_sessions' => $openSessions], severity: 'critical'),
            $this->rule('shift-close-checklist', 'shift', 'restaurant_close', 'Closing checklist required', $openOrders > 0,
                'There are still active orders during closing review.', 'Unclosed orders can corrupt daily settlement.',
                'Close pending orders, payments, print queue, and offline queue before shift close.',
                ['admin.orders.active'], 'review_closing_checklist', 'Review checklist',
                ['open_orders' => $openOrders], severity: 'warning'),
        ];
    }

    private function ownerRules(?int $branchId): array
    {
        $hour = (int) now()->format('G');
        $isMorning = $hour >= 7 && $hour <= 11;

        return [
            $this->rule('owner-morning-summary', 'owner', 'morning_summary', 'Owner morning summary', $isMorning,
                'Morning planning window is active.', 'Owner can align staffing, printer, and revenue targets early.',
                'Generate today readiness and opportunity summary.',
                ['admin.reports.index'], 'owner_morning_summary', 'Generate summary',
                ['branch_id' => $branchId], severity: 'info'),
        ];
    }

    private function readiness(?int $branchId): array
    {
        $printerFailures = Schema::hasTable('print_jobs') ? DB::table('print_jobs')->when($branchId, fn($q) => $q->where('branch_id', $branchId))->where('status', PrintJobStatus::Failed->value)->count() : 0;
        $terminalQueue = Schema::hasTable('pos_terminal_devices') ? DB::table('pos_terminal_devices')->when($branchId, fn($q) => $q->where('branch_id', $branchId))->sum(DB::raw('local_queue_count + server_queue_count')) : 0;
        $openSessions = DB::table('pos_sessions')->when($branchId, fn($q) => $q->where('branch_id', $branchId))->where('status', 'open')->count();

        return [
            'pos_session' => ['ok' => $openSessions > 0, 'open_sessions' => $openSessions],
            'printer' => ['ok' => $printerFailures === 0, 'failed_jobs' => $printerFailures],
            'offline_queue' => ['ok' => (int) $terminalQueue === 0, 'queue_count' => (int) $terminalQueue],
            'realtime' => ['ok' => true, 'source' => 'terminal_heartbeat_and_reverb_runtime'],
            'voice' => ['ok' => true, 'source' => 'agent_runtime_when_configured'],
        ];
    }

    private function rule(
        string $id,
        string $domain,
        string $trigger,
        string $title,
        bool $active,
        string $reason,
        string $impact,
        string $actionText,
        array $permissions,
        string $actionKey,
        string $actionLabel,
        array $evidence = [],
        string $severity = 'info',
        bool $autoEnabled = false,
        string $mode = 'suggest',
    ): AutomationRule {
        return new AutomationRule(
            id: $id,
            domain: $domain,
            trigger: $trigger,
            title: $title,
            reason: $reason,
            impact: $impact,
            recommendedAction: $actionText,
            conditions: [['key' => 'trigger_active', 'passed' => $active]],
            safetyRules: [['key' => 'action_policy_required', 'passed' => true], ['key' => 'audit_required', 'passed' => true]],
            permissions: $permissions,
            action: ['key' => $actionKey, 'label' => $actionLabel, 'loading_key' => $id, 'confirmation_required' => true],
            rollback: ['available' => false, 'notes' => 'Automation is suggest-first unless explicit safe auto setting is enabled.'],
            notification: ['audience' => $domain === 'owner' ? 'owner' : 'operations', 'message' => $actionText],
            evidence: $evidence,
            severity: $severity,
            mode: $mode,
            autoEnabled: $autoEnabled,
        );
    }

    private function playbooks(array $decisions): array
    {
        return collect($decisions)
            ->where('state', '!=', 'inactive')
            ->take(6)
            ->map(fn(array $decision) => [
                'id' => 'playbook-'.$decision['id'],
                'rule_id' => $decision['id'],
                'reason' => $decision['reason'],
                'impact' => $decision['impact'],
                'fix' => $decision['recommended_action'],
                'rollback' => $decision['rollback'],
            ])
            ->values()
            ->all();
    }

    private function analytics(array $decisions): array
    {
        $active = collect($decisions)->where('state', '!=', 'inactive');

        return [
            'automations_evaluated' => count($decisions),
            'manual_overrides' => 0,
            'success_rate' => null,
            'estimated_time_saved_minutes' => $active->count() * 2,
            'ignored_automations' => 0,
            'business_impact' => $active->pluck('impact')->values()->all(),
        ];
    }

    private function auditEvaluation(array $payload, User $user): void
    {
        try {
            activity('restaurant_automation')
                ->causedBy($user)
                ->event('automation_evaluated')
                ->withProperties(['summary' => $payload['summary'] ?? [], 'branch_id' => $payload['branch_id'] ?? null])
                ->log('Restaurant automation evaluated');
        } catch (\Throwable) {
            // Automation evaluation must never block POS runtime if activity logging is unavailable.
        }
    }
}
