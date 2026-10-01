<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Report\Models\FactExpenseDaily;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class ExpenseAnalyticsReport extends Report
{
    public function model(): string
    {
        return FactExpenseDaily::class;
    }

    public function key(): string
    {
        return "expense_analytics";
    }

    public function attributes(): Collection
    {
        return collect([
            "period",
            "expense_category",
            "total_expenses",
            "approved_expenses",
            "pending_expenses",
            "rejected_expenses",
            "total_transactions",
            "approved_transactions",
            "vs_previous_day",
        ]);
    }

    public function columns(): array
    {
        return [
            "expense_category_id",
            "MIN(business_date) as start_date",
            "MAX(business_date) as end_date",
            "MAX(currency) as currency",
            "SUM(total_expenses) as total_expenses",
            "SUM(approved_expenses) as approved_expenses",
            "SUM(pending_expenses) as pending_expenses",
            "SUM(rejected_expenses) as rejected_expenses",
            "SUM(total_transactions) as total_transactions",
            "SUM(approved_transactions) as approved_transactions",
            "AVG(vs_previous_day) as vs_previous_day",
        ];
    }

    public function resource(Model $model): array
    {
        $currency = $model->currency ?: $this->currency;

        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "expense_category" => $model->expenseCategory?->name ?: __("report::reports.all_categories"),
            "total_expenses" => new Money((float) $model->total_expenses, $currency),
            "approved_expenses" => new Money((float) $model->approved_expenses, $currency),
            "pending_expenses" => new Money((float) $model->pending_expenses, $currency),
            "rejected_expenses" => new Money((float) $model->rejected_expenses, $currency),
            "total_transactions" => (int) $model->total_transactions,
            "approved_transactions" => (int) $model->approved_transactions,
            "vs_previous_day" => $model->vs_previous_day ? new Money((float) $model->vs_previous_day, $currency) : null,
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->groupBy('expense_category_id'),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'expense_category_id',
                "label" => __('report::reports.filters.expense_category'),
                "type" => 'select',
                "options" => \Modules\Expense\Models\ExpenseCategory::query()
                    ->where('is_active', true)
                    ->select('id', 'name')
                    ->orderBy('name')
                    ->get()
                    ->map(fn($category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                    ])
                    ->all(),
            ],
        ];
    }

    public function with(): array
    {
        return [
            "expenseCategory:id,name",
        ];
    }

    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "business_date";
    }
}
