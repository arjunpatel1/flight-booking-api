<?php

namespace Modules\Report\Services\EnterpriseReportSummary;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentType;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;
use Modules\Report\Models\BranchDailyBusinessSummary;
use Modules\Report\Models\WaiterDailyCollection;

class EnterpriseReportSummaryService implements EnterpriseReportSummaryServiceInterface
{
    public function rebuildDaily(CarbonInterface|string $date, ?int $branchId = null): void
    {
        $businessDate = $this->dateString($date);

        $this->branchIds($businessDate, $branchId)
            ->each(function (int $currentBranchId) use ($businessDate) {
                $this->rebuildBranchDailyBusinessSummary($businessDate, $currentBranchId);
                $this->rebuildWaiterDailyCollections($businessDate, $currentBranchId);
            });
    }

    private function rebuildBranchDailyBusinessSummary(string $businessDate, int $branchId): void
    {
        $orders = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('order_date', $businessDate)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('SUM(CASE WHEN status = ? AND payment_status = ? THEN 1 ELSE 0 END) as completed_paid_orders', [OrderStatus::Completed->value, OrderPaymentStatus::Paid->value])
            ->selectRaw('SUM(CASE WHEN status NOT IN (?, ?, ?) AND payment_status != ? THEN 1 ELSE 0 END) as pending_orders', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value, OrderStatus::Merged->value, OrderPaymentStatus::Paid->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_orders', [OrderStatus::Cancelled->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as refunded_orders', [OrderStatus::Refunded->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND payment_status = ? THEN subtotal ELSE 0 END), 0) as gross_sales', [OrderStatus::Completed->value, OrderPaymentStatus::Paid->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND payment_status = ? THEN total ELSE 0 END), 0) as net_sales', [OrderStatus::Completed->value, OrderPaymentStatus::Paid->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN status NOT IN (?, ?, ?) THEN due_amount ELSE 0 END), 0) as pending_collections', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value, OrderStatus::Merged->value])
            ->selectRaw('MAX(currency) as currency')
            ->first();

        $discountTotal = $this->orderChildSum('order_discounts', 'amount', $businessDate, $branchId, true);
        $taxTotal = $this->orderChildSum('order_taxes', 'amount', $businessDate, $branchId, true);
        $payments = $this->paymentBreakdown($businessDate, $branchId);
        $cash = $this->cashMovementTotals($businessDate, $branchId);
        $refundTotal = $this->refundTotal($businessDate, $branchId);
        $expenseTotal = $cash['expense_total'];
        $netSales = (float) ($orders->net_sales ?? 0);
        $paymentLedgerTotal = array_sum($payments) - $refundTotal;

        // Rollup runs for an explicit branch; bypass the branch-permission
        // scope so a synchronous rebuild triggered from a tenant-admin request
        // still upserts the correct row instead of a scoped miss + duplicate.
        BranchDailyBusinessSummary::query()->withOutGlobalBranchPermission()->updateOrCreate(
            [
                'branch_id' => $branchId,
                'business_date' => $businessDate,
            ],
            [
                'currency' => $orders->currency,
                'total_orders' => (int) ($orders->total_orders ?? 0),
                'completed_paid_orders' => (int) ($orders->completed_paid_orders ?? 0),
                'pending_orders' => (int) ($orders->pending_orders ?? 0),
                'cancelled_orders' => (int) ($orders->cancelled_orders ?? 0),
                'refunded_orders' => (int) ($orders->refunded_orders ?? 0),
                'gross_sales' => (float) ($orders->gross_sales ?? 0),
                'net_sales' => $netSales,
                'payment_ledger_total' => $paymentLedgerTotal,
                'reconciliation_difference' => $netSales - $paymentLedgerTotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'expense_total' => $expenseTotal,
                'refund_total' => $refundTotal,
                'profit_total' => $netSales - $expenseTotal - $refundTotal,
                'cash_in_hand' => $cash['cash_in_hand'],
                'pending_collections' => (float) ($orders->pending_collections ?? 0),
                'payment_breakdown' => $payments,
                'metadata' => [
                    'source' => 'enterprise_report_summary',
                    'calculation_basis' => 'completed_and_paid_orders',
                    'expense_basis' => 'pos_cash_movements.pay_out',
                    'upi_basis' => [
                        PaymentMethod::UPI->value,
                        PaymentMethod::MobileWallet->value,
                        PaymentMethod::BankTransfer->value,
                    ],
                ],
                'calculated_at' => now(),
            ]
        );
    }

    private function rebuildWaiterDailyCollections(string $businessDate, int $branchId): void
    {
        $waiterIds = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('order_date', $businessDate)
            ->whereNotNull('waiter_id')
            ->distinct()
            ->pluck('waiter_id')
            ->map(fn($id) => (int) $id);

        $waiterIds->each(function (int $waiterId) use ($businessDate, $branchId) {
            $orders = DB::table('orders')
                ->where('branch_id', $branchId)
                ->where('waiter_id', $waiterId)
                ->where('order_date', $businessDate)
                ->selectRaw('COUNT(*) as orders_served')
                ->selectRaw('COUNT(DISTINCT table_id) as tables_served')
                ->selectRaw('COALESCE(SUM(total), 0) as sales_total')
                ->selectRaw('COALESCE(SUM(due_amount), 0) as pending_total')
                ->selectRaw('MAX(currency) as currency')
                ->first();

            $payments = $this->paymentBreakdown($businessDate, $branchId, $waiterId);
            $tipsTotal = $this->tipsTotal($businessDate, $branchId, $waiterId);
            $ordersServed = (int) ($orders->orders_served ?? 0);
            $salesTotal = (float) ($orders->sales_total ?? 0);
            $collectionTotal = array_sum($payments);

            WaiterDailyCollection::query()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'waiter_id' => $waiterId,
                    'business_date' => $businessDate,
                ],
                [
                    'currency' => $orders->currency,
                    'orders_served' => $ordersServed,
                    'tables_served' => (int) ($orders->tables_served ?? 0),
                    'sales_total' => $salesTotal,
                    'collection_total' => $collectionTotal,
                    'cash_total' => $payments[PaymentMethod::Cash->value] ?? 0,
                    'upi_total' => ($payments[PaymentMethod::UPI->value] ?? 0)
                        + ($payments[PaymentMethod::MobileWallet->value] ?? 0)
                        + ($payments[PaymentMethod::BankTransfer->value] ?? 0),
                    'card_total' => $payments[PaymentMethod::Card->value] ?? 0,
                    'tips_total' => $tipsTotal,
                    'pending_total' => (float) ($orders->pending_total ?? 0),
                    'average_bill_value' => $ordersServed > 0 ? round($salesTotal / $ordersServed, 4) : 0,
                    'payment_breakdown' => $payments,
                    'metadata' => [
                        'source' => 'enterprise_report_summary',
                        'tips_basis' => 'pos_cash_movements.tip_in created_by waiter',
                    ],
                    'calculated_at' => now(),
                ]
            );
        });
    }

    private function branchIds(string $businessDate, ?int $branchId): Collection
    {
        if ($branchId) {
            return collect([$branchId]);
        }

        $orderBranches = DB::table('orders')
            ->where('order_date', $businessDate)
            ->whereNotNull('branch_id')
            ->distinct()
            ->pluck('branch_id');

        $cashBranches = DB::table('pos_cash_movements')
            ->whereBetween('occurred_at', $this->dayRange($businessDate))
            ->whereNotNull('branch_id')
            ->distinct()
            ->pluck('branch_id');

        return $orderBranches
            ->merge($cashBranches)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function paymentBreakdown(string $businessDate, int $branchId, ?int $waiterId = null): array
    {
        return DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.branch_id', $branchId)
            ->where('orders.order_date', $businessDate)
            ->whereNull('payments.deleted_at')
            ->where('payments.type', PaymentType::Payment->value)
            ->where('payments.status', PaymentStatus::Completed->value)
            ->when($waiterId, fn($query) => $query->where('orders.waiter_id', $waiterId))
            ->groupBy('payments.method')
            ->selectRaw('payments.method, COALESCE(SUM(payments.amount), 0) as total')
            ->pluck('total', 'method')
            ->map(fn($amount) => (float) $amount)
            ->all();
    }

    private function refundTotal(string $businessDate, int $branchId): float
    {
        return (float) DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.branch_id', $branchId)
            ->where('orders.order_date', $businessDate)
            ->whereNull('payments.deleted_at')
            ->where('payments.type', PaymentType::Refund->value)
            ->where('payments.status', PaymentStatus::Completed->value)
            ->sum('payments.amount');
    }

    private function orderChildSum(string $table, string $column, string $businessDate, int $branchId, bool $completedAndPaid = false): float
    {
        return (float) DB::table($table)
            ->join('orders', 'orders.id', '=', "{$table}.order_id")
            ->where('orders.branch_id', $branchId)
            ->where('orders.order_date', $businessDate)
            ->when($completedAndPaid, fn ($query) => $query
                ->where('orders.status', OrderStatus::Completed->value)
                ->where('orders.payment_status', OrderPaymentStatus::Paid->value))
            ->sum("{$table}.{$column}");
    }

    private function cashMovementTotals(string $businessDate, int $branchId): array
    {
        $rows = DB::table('pos_cash_movements')
            ->where('branch_id', $branchId)
            ->whereBetween('occurred_at', $this->dayRange($businessDate))
            ->whereNull('deleted_at')
            ->groupBy('direction', 'reason')
            ->selectRaw('direction, reason, COALESCE(SUM(amount), 0) as total')
            ->get();

        $cashInHand = 0.0;
        $expenseTotal = 0.0;

        foreach ($rows as $row) {
            $amount = (float) $row->total;
            if ($row->direction === PosCashDirection::In->value) {
                $cashInHand += $amount;
            } elseif ($row->direction === PosCashDirection::Out->value) {
                $cashInHand -= $amount;
            }

            if ($row->direction === PosCashDirection::Out->value && $row->reason === PosCashReason::PayOut->value) {
                $expenseTotal += $amount;
            }
        }

        return [
            'cash_in_hand' => $cashInHand,
            'expense_total' => $expenseTotal,
        ];
    }

    private function tipsTotal(string $businessDate, int $branchId, int $waiterId): float
    {
        return (float) DB::table('pos_cash_movements')
            ->where('branch_id', $branchId)
            ->where('created_by', $waiterId)
            ->whereBetween('occurred_at', $this->dayRange($businessDate))
            ->whereNull('deleted_at')
            ->where('direction', PosCashDirection::In->value)
            ->where('reason', PosCashReason::TipIn->value)
            ->sum('amount');
    }

    private function dateString(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();
    }

    private function dayRange(string $businessDate): array
    {
        $date = Carbon::parse($businessDate);

        return [
            $date->copy()->startOfDay(),
            $date->copy()->endOfDay(),
        ];
    }
}
