<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Support\Enums\DateTimeFormat;

class LoyaltyProgramSummaryReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_programs.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyProgram::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_program_summary";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "program_name",
            "total_customers",
            "total_earned_points",
            "total_redeemed_points",
            "total_expired_points",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_programs.id as program_id",
            "loyalty_programs.name",
            "COUNT(DISTINCT lc.id) as total_customers",
            "COALESCE(SUM(CASE WHEN lt.type in ('earn','bonus','adjust') AND lt.points > 0 THEN lt.points ELSE 0 END), 0) as total_earned_points",
            "COALESCE(SUM(CASE WHEN lt.type = 'redeem' THEN lt.points ELSE 0 END), 0) as total_redeemed_points",
            "COALESCE(SUM(CASE WHEN lt.type = 'expire' THEN lt.points ELSE 0 END), 0) as total_expired_points",
            'MIN(lt.created_at) as start_date',
            'MAX(lt.created_at) as end_date',
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $period = ($model->start_date && $model->end_date)
            ? dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date)
            : null;

        return [
            "period" => $period,
            "program_name" => $model->name,
            "total_customers" => (int)$model->total_customers,
            "total_earned_points" => (int)$model->total_earned_points,
            "total_redeemed_points" => abs((int)$model->total_redeemed_points),
            "total_expired_points" => abs((int)$model->total_expired_points),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->leftJoin('loyalty_customers as lc', 'lc.loyalty_program_id', '=', 'loyalty_programs.id')
                ->leftJoin('loyalty_transactions as lt', 'lt.loyalty_customer_id', '=', 'lc.id')
                ->when(isset($filters['loyalty_program_id']), fn($q) => $q->where('loyalty_programs.id', $filters['loyalty_program_id']))
                ->groupBy('loyalty_programs.id')
        ];
    }
}
