<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Enums\LoyaltyTransactionType;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\Support\Enums\DateTimeFormat;

class TotalExpiredPointsReport extends LoyaltyReport
{
    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_total_expired_points";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "program_name",
            "total_expired_points",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "MIN(loyalty_transactions.created_at) as start_date",
            "MAX(loyalty_transactions.created_at) as end_date",
            "lp.name as program_name",
            "COALESCE(SUM(loyalty_transactions.points), 0) as total_expired_points",
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyTransaction::class;
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $period = dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date);

        return [
            "period" => $period,
            "program_name" => $model->program_name,
            "total_expired_points" => abs((int)$model->total_expired_points),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_customers as lc', 'lc.id', '=', 'loyalty_transactions.loyalty_customer_id')
                ->join('loyalty_programs as lp', 'lp.id', '=', 'lc.loyalty_program_id')
                ->where('loyalty_transactions.type', LoyaltyTransactionType::Expire->value)
                ->when(isset($filters['loyalty_program_id']), fn($q) => $q->where('lp.id', $filters['loyalty_program_id']))
                ->groupBy('lp.id')
        ];
    }
}
