<?php

namespace Modules\Report\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Report\Models\FactExpenseDaily;
use Modules\Support\Eloquent\Model;

class ExpenseAnalyticsQuery
{
    protected Builder $query;
    protected ?int $branchId = null;
    protected ?string $startDate = null;
    protected ?string $endDate = null;
    protected ?string $groupBy = null;

    public function __construct()
    {
        $this->query = FactExpenseDaily::query();
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
            DB::raw('SUM(total_expenses) as total_expenses'),
            DB::raw('SUM(approved_expenses) as approved_expenses'),
            DB::raw('SUM(pending_expenses) as pending_expenses'),
            DB::raw('SUM(rejected_expenses) as rejected_expenses'),
            DB::raw('SUM(total_transactions) as total_transactions'),
            DB::raw('SUM(approved_transactions) as approved_transactions'),
            DB::raw('MAX(currency) as currency'),
        ])->first();

        return [
            'total_expenses' => (float) ($result->total_expenses ?? 0),
            'approved_expenses' => (float) ($result->approved_expenses ?? 0),
            'pending_expenses' => (float) ($result->pending_expenses ?? 0),
            'rejected_expenses' => (float) ($result->rejected_expenses ?? 0),
            'total_transactions' => (int) ($result->total_transactions ?? 0),
            'approved_transactions' => (int) ($result->approved_transactions ?? 0),
            'currency' => $result->currency ?? 'USD',
        ];
    }

    public function getByCategory(): array
    {
        $query = $this->query;

        if ($this->branchId) {
            $query->where('branch_id', $this->branchId);
        }

        if ($this->startDate && $this->endDate) {
            $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
        }

        return $query->select([
            'expense_category_id',
            DB::raw('SUM(total_expenses) as total_expenses'),
            DB::raw('SUM(approved_expenses) as approved_expenses'),
            DB::raw('SUM(total_transactions) as total_transactions'),
            DB::raw('MAX(currency) as currency'),
        ])
            ->whereNotNull('expense_category_id')
            ->groupBy('expense_category_id')
            ->orderByDesc('total_expenses')
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
            DB::raw('SUM(total_expenses) as total_expenses'),
            DB::raw('SUM(approved_expenses) as approved_expenses'),
            DB::raw('SUM(total_transactions) as total_transactions'),
            DB::raw('MAX(currency) as currency'),
        ])
            ->groupBy('business_date')
            ->orderBy('business_date')
            ->get()
            ->toArray();
    }

    public function getOutletComparison(): array
    {
        return $this->query->select([
            'branch_id',
            DB::raw('SUM(total_expenses) as total_expenses'),
            DB::raw('SUM(approved_expenses) as approved_expenses'),
            DB::raw('SUM(total_transactions) as total_transactions'),
            DB::raw('MAX(currency) as currency'),
        ])
            ->whereNotNull('branch_id')
            ->when($this->startDate && $this->endDate, function ($query) {
                $query->whereBetween('business_date', [$this->startDate, $this->endDate]);
            })
            ->groupBy('branch_id')
            ->orderByDesc('total_expenses')
            ->get()
            ->toArray();
    }
}
