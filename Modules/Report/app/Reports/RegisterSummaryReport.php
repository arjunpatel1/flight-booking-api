<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Models\PosSession;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;

class RegisterSummaryReport extends Report
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "closed_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return PosSession::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return 'register_summary';
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "date",
            'register_name',
            'sessions_count',
            'orders_count',
            'system_cash_sales',
            'system_card_sales',
            'system_other_sales',
            'total_sales',
            'total_refunds',
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            'pos_register_id',
            'MAX(branch_id) AS branch_id',
            'COUNT(*) as sessions_count',
            'SUM(orders_count) as orders_count',
            'SUM(system_cash_sales) as system_cash_sales',
            'SUM(system_card_sales) as system_card_sales',
            'SUM(system_other_sales) as system_other_sales',
            'SUM(total_sales) as total_sales',
            'SUM(total_refunds) as total_refunds',
            "MIN(closed_at) as start_date",
            "MAX(closed_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "date" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            'register_name' => $model->posRegister->name,
            'sessions_count' => (int)$model->sessions_count,
            'orders_count' => (int)$model->orders_count,
            'system_cash_sales' => $model->system_cash_sales,
            'system_card_sales' => $model->system_card_sales,
            'system_other_sales' => $model->system_other_sales,
            'total_sales' => $model->total_sales,
            'total_refunds' => $model->total_refunds
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->where("status", PosSessionStatus::Closed)
                ->groupBy('pos_register_id')
        ];
    }

    /** @inheritDoc */
    public function with(): array
    {
        return [
            'posRegister:id,name',
            'branch:id,name,currency',
        ];
    }

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
