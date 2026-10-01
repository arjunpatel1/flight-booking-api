<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Support\Enums\DateTimeFormat;

class SystemPointsBalanceReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_customers.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyCustomer::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_system_points_balance";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "program_name",
            "total_active_customers",
            "total_points_balance",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "lp.name as program_name",
            "COUNT(DISTINCT loyalty_customers.id) as total_active_customers",
            "COALESCE(SUM(loyalty_customers.points_balance), 0) as total_points_balance",
            "MIN(loyalty_customers.created_at) as start_date",
            "MAX(loyalty_customers.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "program_name" => $model->program_name,
            "total_active_customers" => (int)$model->total_active_customers,
            "total_points_balance" => (int)$model->total_points_balance,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_programs as lp', 'lp.id', '=', 'loyalty_customers.loyalty_program_id')
                ->groupBy('lp.id')
        ];
    }
}
