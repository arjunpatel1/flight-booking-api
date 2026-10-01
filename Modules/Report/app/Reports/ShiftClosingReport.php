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

class ShiftClosingReport extends Report
{
    public function model(): string
    {
        return FactShiftDaily::class;
    }

    public function key(): string
    {
        return "shift_closing";
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
            "cash_total",
            "card_total",
            "upi_total",
            "other_total",
            "total_sales",
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
            "SUM(cash_total) as cash_total",
            "SUM(card_total) as card_total",
            "SUM(upi_total) as upi_total",
            "SUM(other_total) as other_total",
            "SUM(net_sales) as total_sales",
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
            "cash_total" => new Money((float) $model->cash_total, $currency),
            "card_total" => new Money((float) $model->card_total, $currency),
            "upi_total" => new Money((float) $model->upi_total, $currency),
            "other_total" => new Money((float) $model->other_total, $currency),
            "total_sales" => new Money((float) $model->total_sales, $currency),
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
