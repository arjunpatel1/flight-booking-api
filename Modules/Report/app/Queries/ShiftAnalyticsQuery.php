<?php

namespace Modules\Report\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Report\Models\FactShiftDaily;

class ShiftAnalyticsQuery
{
    protected Builder $query;
    protected ?int $branchId = null;
    protected ?string $startDate = null;
    protected ?string $endDate = null;
    protected ?string $groupBy = null;

    public function __construct()
    {
        $this->query = FactShiftDaily::query();
    }

    public function forBranch(?int $branchId): self
    {
        $this->branchId = $branchId;
        return $this;
    }

    public function forDateRange(?string $startDate, ?string $endDate): self
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        return $this;
    }

    public function groupBy(?string $groupBy): self
    {
        $this->groupBy = $groupBy;
        return $this;
    }

    public function getSummary(): array
    {
        $query = $this->query;

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
        }

        $result = $query->select([
            DB::raw('SUM(total_orders) as total_orders'),
            DB::raw('SUM(completed_orders) as completed_orders'),
            DB::raw('SUM(cancelled_orders) as cancelled_orders'),
            DB::raw('SUM(gross_sales) as gross_sales'),
            DB::raw('SUM(net_sales) as net_sales'),
            DB::raw('AVG(average_order_value) as average_order_value'),
            DB::raw('SUM(opening_float) as opening_float'),
            DB::raw('SUM(declared_cash) as declared_cash'),
            DB::raw('SUM(system_cash_sales) as system_cash_sales'),
            DB::raw('SUM(cash_over_short) as cash_over_short'),
            DB::raw('SUM(cash_total) as cash_total'),
            DB::raw('SUM(card_total) as card_total'),
            DB::raw('SUM(upi_total) as upi_total'),
            DB::raw('SUM(other_total) as other_total'),
            DB::raw('AVG(orders_per_hour) as orders_per_hour'),
            DB::raw('AVG(sales_per_hour) as sales_per_hour'),
            DB::raw('MAX(currency) as currency'),
        ])->first();

        return [
            'total_orders' => (int) ($result->total_orders ?? 0),
            'completed_orders' => (int) ($result->completed_orders ?? 0),
            'cancelled_orders' => (int) ($result->cancelled_orders ?? 0),
            'gross_sales' => (float) ($result->gross_sales ?? 0),
            'net_sales' => (float) ($result->net_sales ?? 0),
            'average_order_value' => (float) ($result->average_order_value ?? 0),
            'opening_float' => (float) ($result->opening_float ?? 0),
            'declared_cash' => (float) ($result->declared_cash ?? 0),
            'system_cash_sales' => (float) ($result->system_cash_sales ?? 0),
            'cash_over_short' => (float) ($result->cash_over_short ?? 0),
            'cash_total' => (float) ($result->cash_total ?? 0),
            'card_total' => (float) ($result->card_total ?? 0),
            'upi_total' => (float) ($result->upi_total ?? 0),
            'other_total' => (float) ($result->other_total ?? 0),
            'orders_per_hour' => (float) ($result->orders_per_hour ?? 0),
            'sales_per_hour' => (float) ($result->sales_per_hour ?? 0),
            'currency' => $result->currency ?? 'USD',
        ];
    }

    public function getByShift(): array
    {
        $query = $this->query;

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
        }

        return $query->select([
            'shift_id',
            DB::raw('SUM(total_orders) as total_orders'),
            DB::raw('SUM(completed_orders) as completed_orders'),
            DB::raw('SUM(gross_sales) as gross_sales'),
            DB::raw('SUM(net_sales) as net_sales'),
            DB::raw('SUM(cash_over_short) as cash_over_short'),
            DB::raw('AVG(orders_per_hour) as orders_per_hour'),
            DB::raw('AVG(sales_per_hour) as sales_per_hour'),
            DB::raw('MAX(currency) as currency'),
        ])
            ->whereNotNull('shift_id')
            ->groupBy('shift_id')
            ->orderByDesc('gross_sales')
            ->get()
            ->toArray();
    }

    public function getByUser(): array
    {
        $query = $this->query;

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
        }

        return $query->select([
            'user_id',
            DB::raw('SUM(total_orders) as total_orders'),
            DB::raw('SUM(completed_orders) as completed_orders'),
            DB::raw('SUM(gross_sales) as gross_sales'),
            DB::raw('SUM(net_sales) as net_sales'),
            DB::raw('SUM(cash_over_short) as cash_over_short'),
            DB::raw('AVG(orders_per_hour) as orders_per_hour'),
            DB::raw('AVG(sales_per_hour) as sales_per_hour'),
            DB::raw('MAX(currency) as currency'),
        ])
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('gross_sales')
            ->get()
            ->toArray();
    }

    public function getDailyTrend(): array
    {
        $query = $this->query;

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
        }

        return $query->select([
            'business_date',
            DB::raw('SUM(total_orders) as total_orders'),
            DB::raw('SUM(gross_sales) as gross_sales'),
            DB::raw('SUM(net_sales) as net_sales'),
            DB::raw('SUM(cash_over_short) as cash_over_short'),
            DB::raw('AVG(orders_per_hour) as orders_per_hour'),
            DB::raw('MAX(currency) as currency'),
        ])
            ->groupBy('business_date')
            ->orderBy('business_date')
            ->get()
            ->toArray();
    }

    public function getCashReconciliation(): array
    {
        $query = $this->query;

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
        }

        return $query->select([
            'business_date',
            'user_id',
            'pos_session_id',
            DB::raw('SUM(opening_float) as opening_float'),
            DB::raw('SUM(declared_cash) as declared_cash'),
            DB::raw('SUM(system_cash_sales) as system_cash_sales'),
            DB::raw('SUM(cash_over_short) as cash_over_short'),
            DB::raw('MAX(currency) as currency'),
        ])
            ->whereNotNull('pos_session_id')
            ->groupBy('business_date', 'user_id', 'pos_session_id')
            ->orderBy('business_date')
            ->get()
            ->toArray();
    }
}
