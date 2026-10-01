<?php

namespace Modules\Report\Jobs;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentType;
use Modules\Report\Models\FactInventoryDaily;
use Modules\Report\Models\FactItemSalesHourly;
use Modules\Report\Models\FactSalesDaily;

class BuildAnalyticsFactTablesJob implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public CarbonInterface|string $date,
        public ?int $branchId = null,
        public bool $forceRebuild = false
    ) {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        $businessDate = $this->parseDate($this->date);

        $branchIds = $this->getBranchIds($businessDate, $this->branchId);

        foreach ($branchIds as $branchId) {
            $this->buildSalesDailyFact($businessDate, $branchId);
            $this->buildItemSalesHourlyFact($businessDate, $branchId);
            $this->buildInventoryDailyFact($businessDate, $branchId);
        }
    }

    private function buildSalesDailyFact(string $businessDate, int $branchId): void
    {
        $existing = FactSalesDaily::query()
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->first();

        if ($existing && ! $this->forceRebuild) {
            return;
        }

        $orders = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('order_date', $businessDate)
            ->where('status', OrderStatus::Completed->value)
            ->where('payment_status', OrderPaymentStatus::Paid->value)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('COUNT(DISTINCT customer_id) as unique_customers')
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as dine_in_orders', [OrderType::DineIn->value])
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as takeaway_orders', [OrderType::Takeaway->value])
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as delivery_orders', [OrderType::Delivery->value])
            ->selectRaw('COALESCE(SUM(subtotal), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(total), 0) as net_sales')
            ->selectRaw('MAX(currency) as currency')
            ->first();

        $exceptionCounts = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('order_date', $businessDate)
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled_orders', [OrderStatus::Cancelled->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as refunded_orders', [OrderStatus::Refunded->value])
            ->first();

        $discountTotal = $this->orderChildSum('order_discounts', 'amount', $businessDate, $branchId);
        $taxTotal = $this->orderChildSum('order_taxes', 'amount', $businessDate, $branchId);
        $refundTotal = $this->refundTotal($businessDate, $branchId);
        $paymentBreakdown = $this->paymentBreakdown($businessDate, $branchId);
        $orderTypeBreakdown = $this->orderTypeBreakdown($businessDate, $branchId);
        $aggregatorMetrics = $this->aggregatorMetrics($businessDate, $branchId);

        $totalOrders = (int) ($orders->total_orders ?? 0);
        $netSales = (float) ($orders->net_sales ?? 0);

        // Calculate new vs returning customers
        $newCustomers = $this->newCustomersCount($businessDate, $branchId);
        $returningCustomers = max(0, ($orders->unique_customers ?? 0) - $newCustomers);

        FactSalesDaily::query()->withOutGlobalBranchPermission()->updateOrCreate(
            [
                'branch_id' => $branchId,
                'business_date' => $businessDate,
            ],
            [
                'currency' => $orders->currency,
                'total_orders' => $totalOrders,
                'dine_in_orders' => (int) ($orders->dine_in_orders ?? 0),
                'takeaway_orders' => (int) ($orders->takeaway_orders ?? 0),
                'delivery_orders' => (int) ($orders->delivery_orders ?? 0),
                'cancelled_orders' => (int) ($exceptionCounts->cancelled_orders ?? 0),
                'refunded_orders' => (int) ($exceptionCounts->refunded_orders ?? 0),
                'gross_sales' => (float) ($orders->gross_sales ?? 0),
                'net_sales' => $netSales,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'refund_total' => $refundTotal,
                'average_order_value' => $totalOrders > 0 ? round($netSales / $totalOrders, 4) : 0,
                'unique_customers' => (int) ($orders->unique_customers ?? 0),
                'new_customers' => $newCustomers,
                'returning_customers' => $returningCustomers,
                'cash_total' => $paymentBreakdown[PaymentMethod::Cash->value] ?? 0,
                'card_total' => $paymentBreakdown[PaymentMethod::Card->value] ?? 0,
                // Older POS versions stored UPI as either mobile_wallet or
                // bank_transfer. Include both legacy rails until those rows
                // are explicitly reclassified; all new writes use `upi`.
                'upi_total' => ($paymentBreakdown[PaymentMethod::UPI->value] ?? 0)
                    + ($paymentBreakdown[PaymentMethod::BankTransfer->value] ?? 0)
                    + ($paymentBreakdown[PaymentMethod::MobileWallet->value] ?? 0),
                'wallet_total' => $paymentBreakdown[PaymentMethod::MobileWallet->value] ?? 0,
                'aggregator_gross_sales' => $aggregatorMetrics['gross_sales'] ?? 0,
                'aggregator_commission' => $aggregatorMetrics['commission'] ?? 0,
                'aggregator_payout' => $aggregatorMetrics['payout'] ?? 0,
                'payment_breakdown' => $paymentBreakdown,
                'order_type_breakdown' => $orderTypeBreakdown,
                'metadata' => [
                    'source' => 'analytics_fact_tables',
                    'rebuilt_at' => now()->toIso8601String(),
                ],
                'calculated_at' => now(),
            ]
        );
    }

    private function buildItemSalesHourlyFact(string $businessDate, int $branchId): void
    {
        $categoryColumn = Schema::hasColumn('order_products', 'category_id')
            ? 'order_products.category_id'
            : 'NULL';
        $discountColumn = Schema::hasColumn('order_products', 'discount_amount')
            ? 'SUM(order_products.discount_amount)'
            : '0';

        $hourlyData = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('orders.branch_id', $branchId)
            ->where('orders.order_date', $businessDate)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw("
                HOUR(orders.created_at) as hour,
                order_products.product_id,
                {$categoryColumn} as category_id,
                MAX(orders.currency) as currency,
                SUM(order_products.quantity) as quantity_sold,
                SUM(order_products.subtotal) as gross_sales,
                SUM(order_products.total) as net_sales,
                {$discountColumn} as discount_total,
                SUM(order_products.cost_price * order_products.quantity) as cost_total,
                COUNT(DISTINCT orders.id) as order_count
            ")
            ->groupBy('hour', 'order_products.product_id')
            ->get();

        foreach ($hourlyData as $row) {
            $netSales = (float) $row->net_sales;
            $costTotal = (float) $row->cost_total;
            $profitTotal = $netSales - $costTotal;
            $marginPercent = $netSales > 0 ? round(($profitTotal / $netSales) * 100, 2) : 0;

            FactItemSalesHourly::query()->withOutGlobalBranchPermission()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'business_date' => $businessDate,
                    'hour' => (int) $row->hour,
                    'product_id' => $row->product_id,
                ],
                [
                    'category_id' => $row->category_id,
                    'currency' => $row->currency,
                    'quantity_sold' => (float) $row->quantity_sold,
                    'gross_sales' => (float) $row->gross_sales,
                    'net_sales' => $netSales,
                    'discount_total' => (float) $row->discount_total,
                    'cost_total' => $costTotal,
                    'profit_total' => $profitTotal,
                    'margin_percent' => $marginPercent,
                    'order_count' => (int) $row->order_count,
                    'metadata' => [
                        'source' => 'analytics_fact_tables',
                        'rebuilt_at' => now()->toIso8601String(),
                    ],
                    'calculated_at' => now(),
                ]
            );
        }
    }

    private function buildInventoryDailyFact(string $businessDate, int $branchId): void
    {
        if (! Schema::hasColumn('stock_movements', 'occurred_at')) {
            return;
        }

        // Get all ingredients with stock movements for this branch/date
        $ingredients = DB::table('stock_movements')
            ->where('branch_id', $branchId)
            ->whereDate('occurred_at', $businessDate)
            ->distinct()
            ->pluck('ingredient_id');

        foreach ($ingredients as $ingredientId) {
            $openingStock = $this->getStockAtDate($branchId, $ingredientId, $businessDate, 'opening');
            $closingStock = $this->getStockAtDate($branchId, $ingredientId, $businessDate, 'closing');

            $movements = DB::table('stock_movements')
                ->where('branch_id', $branchId)
                ->where('ingredient_id', $ingredientId)
                ->whereDate('occurred_at', $businessDate)
                ->selectRaw('
                    type,
                    SUM(quantity) as total_qty,
                    SUM(quantity * unit_cost) as total_value
                ')
                ->groupBy('type')
                ->get()
                ->keyBy('type');

            $purchasedQty = (float) ($movements['purchase']->total_qty ?? 0);
            $consumedQty = (float) ($movements['consumption']->total_qty ?? 0);
            $wastedQty = (float) ($movements['waste']->total_qty ?? 0);
            $transferredInQty = (float) ($movements['transfer_in']->total_qty ?? 0);
            $transferredOutQty = (float) ($movements['transfer_out']->total_qty ?? 0);

            $purchaseValue = (float) ($movements['purchase']->total_value ?? 0);
            $consumedValue = (float) ($movements['consumption']->total_value ?? 0);
            $wastedValue = (float) ($movements['waste']->total_value ?? 0);

            $ingredient = DB::table('ingredients')
                ->where('id', $ingredientId)
                ->first();

            // Skip if ingredient no longer exists
            if (! $ingredient) {
                continue;
            }

            FactInventoryDaily::query()->withOutGlobalBranchPermission()->updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'business_date' => $businessDate,
                    'ingredient_id' => $ingredientId,
                    'warehouse_id' => null,
                ],
                [
                    'currency' => setting('default_currency') ?? 'INR',
                    'opening_stock' => $openingStock,
                    'purchased_qty' => $purchasedQty,
                    'consumed_qty' => $consumedQty,
                    'wasted_qty' => $wastedQty,
                    'transferred_in_qty' => $transferredInQty,
                    'transferred_out_qty' => $transferredOutQty,
                    'closing_stock' => $closingStock,
                    'opening_value' => $openingStock * ($ingredient->unit_cost ?? 0),
                    'purchase_value' => $purchaseValue,
                    'consumed_value' => $consumedValue,
                    'wasted_value' => $wastedValue,
                    'closing_value' => $closingStock * ($ingredient->unit_cost ?? 0),
                    'unit' => $ingredient->unit ?? null,
                    'metadata' => [
                        'source' => 'analytics_fact_tables',
                        'rebuilt_at' => now()->toIso8601String(),
                    ],
                    'calculated_at' => now(),
                ]
            );
        }
    }

    private function getBranchIds(string $businessDate, ?int $branchId): Collection
    {
        if ($branchId) {
            return collect([$branchId]);
        }

        return DB::table('orders')
            ->where('order_date', $businessDate)
            ->whereNotNull('branch_id')
            ->distinct()
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id);
    }

    private function paymentBreakdown(string $businessDate, int $branchId): array
    {
        return DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.branch_id', $branchId)
            ->where('orders.order_date', $businessDate)
            ->whereNull('payments.deleted_at')
            ->where('payments.type', PaymentType::Payment->value)
            ->where('payments.status', PaymentStatus::Completed->value)
            ->where('orders.status', OrderStatus::Completed->value)
            ->where('orders.payment_status', OrderPaymentStatus::Paid->value)
            ->groupBy('payments.method')
            ->selectRaw('payments.method, COALESCE(SUM(payments.amount), 0) as total')
            ->pluck('total', 'method')
            ->map(fn ($amount) => (float) $amount)
            ->all();
    }

    private function orderTypeBreakdown(string $businessDate, int $branchId): array
    {
        return DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('order_date', $businessDate)
            ->where('status', OrderStatus::Completed->value)
            ->where('payment_status', OrderPaymentStatus::Paid->value)
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) as count, COALESCE(SUM(total), 0) as total')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->type => [
                    'count' => (int) $row->count,
                    'total' => (float) $row->total,
                ],
            ])
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
            ->sum('payments.amount');
    }

    private function orderChildSum(string $table, string $column, string $businessDate, int $branchId): float
    {
        return (float) DB::table($table)
            ->join('orders', 'orders.id', '=', "{$table}.order_id")
            ->where('orders.branch_id', $branchId)
            ->where('orders.order_date', $businessDate)
            ->where('orders.status', OrderStatus::Completed->value)
            ->where('orders.payment_status', OrderPaymentStatus::Paid->value)
            ->sum("{$table}.{$column}");
    }

    private function newCustomersCount(string $businessDate, int $branchId): int
    {
        if (Schema::hasColumn('orders', 'customer_first_order')) {
            return (int) DB::table('orders')
                ->where('branch_id', $branchId)
                ->where('order_date', $businessDate)
                ->where('customer_first_order', true)
                ->count();
        }

        return (int) DB::table('orders as today_orders')
            ->where('today_orders.branch_id', $branchId)
            ->where('today_orders.order_date', $businessDate)
            ->whereNotNull('today_orders.customer_id')
            ->whereNotExists(function ($query) use ($businessDate, $branchId) {
                $query->selectRaw('1')
                    ->from('orders as previous_orders')
                    ->whereColumn('previous_orders.customer_id', 'today_orders.customer_id')
                    ->where('previous_orders.branch_id', $branchId)
                    ->where('previous_orders.order_date', '<', $businessDate);
            })
            ->distinct('today_orders.customer_id')
            ->count('today_orders.customer_id');
    }

    private function aggregatorMetrics(string $businessDate, int $branchId): array
    {
        if (! Schema::hasColumn('orders', 'aggregator_id')) {
            return [
                'gross_sales' => 0,
                'commission' => 0,
                'payout' => 0,
            ];
        }

        $commissionColumn = Schema::hasColumn('orders', 'aggregator_commission')
            ? 'COALESCE(SUM(aggregator_commission), 0)'
            : '0';
        $payoutColumn = Schema::hasColumn('orders', 'aggregator_payout')
            ? 'COALESCE(SUM(aggregator_payout), 0)'
            : '0';

        $orders = DB::table('orders')
            ->where('branch_id', $branchId)
            ->where('order_date', $businessDate)
            ->whereNotNull('aggregator_id')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as gross_sales')
            ->selectRaw("{$commissionColumn} as commission")
            ->selectRaw("{$payoutColumn} as payout")
            ->first();

        return [
            'gross_sales' => (float) ($orders->gross_sales ?? 0),
            'commission' => (float) ($orders->commission ?? 0),
            'payout' => (float) ($orders->payout ?? 0),
        ];
    }

    private function getStockAtDate(int $branchId, int $ingredientId, string $businessDate, string $type): float
    {
        $date = Carbon::parse($businessDate);

        if ($type === 'opening') {
            $date->startOfDay();
        } else {
            $date->endOfDay();
        }

        return (float) DB::table('stock_movements')
            ->where('branch_id', $branchId)
            ->where('ingredient_id', $ingredientId)
            ->where('occurred_at', '<=', $date)
            ->selectRaw('SUM(CASE WHEN type IN (?, ?) THEN quantity ELSE -quantity END) as stock', ['purchase', 'transfer_in'])
            ->value('stock') ?? 0;
    }

    private function parseDate(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();
    }
}
