<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Report\Models\FactShiftDaily;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class CashReconciliationReport extends Report
{
    public function model(): string
    {
        return FactShiftDaily::class;
    }

    public function key(): string
    {
        return "cash_reconciliation";
    }

    public function attributes(): Collection
    {
        return collect([
            "period",
            "user",
            "pos_session_id",
            "opening_float",
            "declared_cash",
            "system_cash_sales",
            "cash_over_short",
            "difference_amount",
        ]);
    }

    public function columns(): array
    {
        return [
            "user_id",
            "pos_session_id",
            "business_date",
            "MAX(currency) as currency",
            "SUM(opening_float) as opening_float",
            "SUM(declared_cash) as declared_cash",
            "SUM(system_cash_sales) as system_cash_sales",
            "SUM(cash_over_short) as cash_over_short",
            "SUM(declared_cash - system_cash_sales) as difference_amount",
        ];
    }

    public function resource(Model $model): array
    {
        $currency = $model->currency ?: $this->currency;

        return [
            "period" => dateTimeFormat($model->business_date, DateTimeFormat::Date),
            "user" => $model->user?->name ?: __("report::reports.unassigned"),
            "pos_session_id" => $model->pos_session_id,
            "opening_float" => new Money((float) $model->opening_float, $currency),
            "declared_cash" => new Money((float) $model->declared_cash, $currency),
            "system_cash_sales" => new Money((float) $model->system_cash_sales, $currency),
            "cash_over_short" => new Money((float) $model->cash_over_short, $currency),
            "difference_amount" => new Money((float) $model->difference_amount, $currency),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->whereNotNull('pos_session_id')
                ->groupBy(["user_id", "pos_session_id", "business_date"]),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            [
                "key" => 'user_id',
                "label" => __('report::reports.filters.user'),
                "type" => 'select',
                "options" => \Modules\User\Models\User::query()
                    ->withOutGlobalBranchPermission()
                    ->select('id', 'name')
                    ->orderBy('name')
                    ->get()
                    ->map(fn($user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ])
                    ->all(),
            ],
        ];
    }

    public function with(): array
    {
        return [
            "user:id,name",
        ];
    }

    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "business_date";
    }
}
