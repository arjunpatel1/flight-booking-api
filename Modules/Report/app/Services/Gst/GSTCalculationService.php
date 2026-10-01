<?php

namespace Modules\Report\Services\Gst;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Modules\Support\Money;

/**
 * Centralizes all GST calculations.
 * Never calculate GST in controllers or Vue components — use this service.
 */
class GSTCalculationService
{
    /**
     * Build the GST pivot subquery for a date range and optional branch.
     * Returns an associative array with cgst, sgst, igst, cess, total_tax.
     *
     * @param string|null $from Y-m-d
     * @param string|null $to   Y-m-d
     * @param int|null    $branchId
     * @param string      $currency
     */
    public function dashboardKpis(
        ?string $from,
        ?string $to,
        ?int $branchId,
        string $currency
    ): array {
        $query = DB::table('orders')
            ->leftJoin(
                DB::raw("(SELECT ot.order_id,
                    SUM(CASE WHEN t.gst_type = 'CGST' THEN ot.amount ELSE 0 END) as cgst,
                    SUM(CASE WHEN t.gst_type = 'SGST' THEN ot.amount ELSE 0 END) as sgst,
                    SUM(CASE WHEN t.gst_type = 'IGST' THEN ot.amount ELSE 0 END) as igst,
                    SUM(CASE WHEN t.gst_type = 'CESS' THEN ot.amount ELSE 0 END) as cess,
                    SUM(ot.amount) as total_tax
                FROM order_taxes ot
                LEFT JOIN taxes t ON t.id = ot.tax_id
                GROUP BY ot.order_id) gst_pivot"),
                fn($j) => $j->on('orders.id', '=', 'gst_pivot.order_id')
            )
            ->whereNotIn('orders.status', ['cancelled', 'refunded', 'merged'])
            ->when($from, fn($q) => $q->whereDate('orders.created_at', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('orders.created_at', '<=', $to))
            ->when($this->allowedBranchIds($branchId), fn($q, Collection $ids) => $q->whereIn('orders.branch_id', $ids))
            ->selectRaw("
                COUNT(DISTINCT orders.id) as total_orders,
                COALESCE(SUM(orders.total - COALESCE(gst_pivot.total_tax, 0)), 0) as taxable_sales,
                COALESCE(SUM(COALESCE(gst_pivot.cgst, 0)), 0) as total_cgst,
                COALESCE(SUM(COALESCE(gst_pivot.sgst, 0)), 0) as total_sgst,
                COALESCE(SUM(COALESCE(gst_pivot.igst, 0)), 0) as total_igst,
                COALESCE(SUM(COALESCE(gst_pivot.cess, 0)), 0) as total_cess,
                COALESCE(SUM(COALESCE(gst_pivot.total_tax, 0)), 0) as total_gst,
                COALESCE(SUM(orders.total), 0) as gross_total,
                COUNT(DISTINCT CASE WHEN orders.gstin IS NOT NULL AND orders.gstin != '' THEN orders.id END) as b2b_orders,
                COUNT(DISTINCT CASE WHEN orders.gstin IS NULL OR orders.gstin = '' THEN orders.id END) as b2c_orders
            ")
            ->first();

        $row = (array) $query;

        return [
            "total_orders"   => (int) $row['total_orders'],
            "taxable_sales"  => new Money((float) $row['taxable_sales'], $currency),
            "total_cgst"     => new Money((float) $row['total_cgst'], $currency),
            "total_sgst"     => new Money((float) $row['total_sgst'], $currency),
            "total_igst"     => new Money((float) $row['total_igst'], $currency),
            "total_cess"     => new Money((float) $row['total_cess'], $currency),
            "total_gst"      => new Money((float) $row['total_gst'], $currency),
            "gross_total"    => new Money((float) $row['gross_total'], $currency),
            "b2b_orders"     => (int) $row['b2b_orders'],
            "b2c_orders"     => (int) $row['b2c_orders'],
        ];
    }

    /**
     * Daily GST trend for last N days.
     */
    public function dailyTrend(?string $from, ?string $to, ?int $branchId, string $currency): array
    {
        return DB::table('orders')
            ->leftJoin(
                DB::raw("(SELECT ot.order_id, SUM(ot.amount) as total_tax
                    FROM order_taxes ot GROUP BY ot.order_id) gt"),
                fn($j) => $j->on('orders.id', '=', 'gt.order_id')
            )
            ->whereNotIn('orders.status', ['cancelled', 'refunded', 'merged'])
            ->when($from, fn($q) => $q->whereDate('orders.created_at', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('orders.created_at', '<=', $to))
            ->when($this->allowedBranchIds($branchId), fn($q, Collection $ids) => $q->whereIn('orders.branch_id', $ids))
            ->selectRaw("DATE(orders.created_at) as date,
                COALESCE(SUM(orders.total - COALESCE(gt.total_tax, 0)), 0) as taxable,
                COALESCE(SUM(COALESCE(gt.total_tax, 0)), 0) as total_gst,
                COALESCE(SUM(orders.total), 0) as gross")
            ->groupByRaw("DATE(orders.created_at)")
            ->orderByRaw("DATE(orders.created_at) ASC")
            ->get()
            ->map(fn($row) => [
                "date"      => $row->date,
                "taxable"   => (float) $row->taxable,
                "total_gst" => (float) $row->total_gst,
                "gross"     => (float) $row->gross,
            ])
            ->toArray();
    }

    /**
     * Monthly GST trend for last N months.
     */
    public function monthlyTrend(?string $from, ?string $to, ?int $branchId, string $currency): array
    {
        return DB::table('orders')
            ->leftJoin(
                DB::raw("(SELECT ot.order_id, SUM(ot.amount) as total_tax
                    FROM order_taxes ot GROUP BY ot.order_id) gt"),
                fn($j) => $j->on('orders.id', '=', 'gt.order_id')
            )
            ->whereNotIn('orders.status', ['cancelled', 'refunded', 'merged'])
            ->when($from, fn($q) => $q->whereDate('orders.created_at', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('orders.created_at', '<=', $to))
            ->when($this->allowedBranchIds($branchId), fn($q, Collection $ids) => $q->whereIn('orders.branch_id', $ids))
            ->selectRaw("DATE_FORMAT(orders.created_at, '%Y-%m') as month,
                COALESCE(SUM(orders.total - COALESCE(gt.total_tax, 0)), 0) as taxable,
                COALESCE(SUM(COALESCE(gt.total_tax, 0)), 0) as total_gst,
                COALESCE(SUM(orders.total), 0) as gross")
            ->groupByRaw("DATE_FORMAT(orders.created_at, '%Y-%m')")
            ->orderByRaw("DATE_FORMAT(orders.created_at, '%Y-%m') ASC")
            ->get()
            ->map(fn($row) => [
                "month"     => $row->month,
                "taxable"   => (float) $row->taxable,
                "total_gst" => (float) $row->total_gst,
                "gross"     => (float) $row->gross,
            ])
            ->toArray();
    }

    /**
     * GST breakdown by order type.
     */
    public function byOrderType(?string $from, ?string $to, ?int $branchId): array
    {
        return DB::table('orders')
            ->leftJoin(
                DB::raw("(SELECT ot.order_id, SUM(ot.amount) as total_tax
                    FROM order_taxes ot GROUP BY ot.order_id) gt"),
                fn($j) => $j->on('orders.id', '=', 'gt.order_id')
            )
            ->whereNotIn('orders.status', ['cancelled', 'refunded', 'merged'])
            ->when($from, fn($q) => $q->whereDate('orders.created_at', '>=', $from))
            ->when($to,   fn($q) => $q->whereDate('orders.created_at', '<=', $to))
            ->when($this->allowedBranchIds($branchId), fn($q, Collection $ids) => $q->whereIn('orders.branch_id', $ids))
            ->selectRaw("orders.type as order_type,
                COUNT(DISTINCT orders.id) as total_orders,
                COALESCE(SUM(COALESCE(gt.total_tax, 0)), 0) as total_gst")
            ->groupBy('orders.type')
            ->get()
            ->map(fn($row) => [
                "order_type"  => $row->order_type,
                "total_orders" => (int) $row->total_orders,
                "total_gst"   => (float) $row->total_gst,
            ])
            ->toArray();
    }

    /**
     * Resolve the exact branches the current actor may report on. Returning an
     * empty Collection deliberately produces `where 0 = 1`; it must never
     * degrade into an unscoped, platform-wide query.
     */
    private function allowedBranchIds(?int $requestedBranchId): ?Collection
    {
        $user = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $requestedBranchId ? collect([$requestedBranchId]) : null;
        }

        $tenantId = $user->tenantId();
        if (! $tenantId) {
            return collect();
        }

        $effectiveBranchId = $user->assignedToBranch()
            ? $user->branchId()
            : $requestedBranchId;

        return DB::table('branches')
            ->where('tenant_id', $tenantId)
            ->when($effectiveBranchId, fn($query) => $query->where('id', $effectiveBranchId))
            ->pluck('id');
    }
}
