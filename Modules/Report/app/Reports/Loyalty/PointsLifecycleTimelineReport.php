<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\GlobalStructureFilters;

class PointsLifecycleTimelineReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_transactions.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyTransaction::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_points_lifecycle_timeline";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "date",
            "earned_points",
            "redeemed_points",
            "expired_points",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "DATE(loyalty_transactions.created_at) as activity_date",
            "COALESCE(SUM(CASE WHEN loyalty_transactions.type in ('earn','bonus','adjust') AND loyalty_transactions.points > 0 THEN loyalty_transactions.points ELSE 0 END), 0) as earned_points",
            "COALESCE(SUM(CASE WHEN loyalty_transactions.type = 'redeem' THEN loyalty_transactions.points ELSE 0 END), 0) as redeemed_points",
            "COALESCE(SUM(CASE WHEN loyalty_transactions.type = 'expire' THEN loyalty_transactions.points ELSE 0 END), 0) as expired_points",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "date" => dateTimeFormat($model->activity_date, DateTimeFormat::Date),
            "earned_points" => (int)$model->earned_points,
            "redeemed_points" => abs((int)$model->redeemed_points),
            "expired_points" => abs((int)$model->expired_points),
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
                ->when(isset($filters['loyalty_program_id']), fn($q) => $q->where('lp.id', $filters['loyalty_program_id']))
                ->groupBy('activity_date')
                ->orderBy('activity_date')
        ];
    }

    /**
     * Override global filters to only include date range and optional grouping.
     *
     * @return array
     */
    public function globalFilters(): array
    {
        return [
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
            $this->programFilter(),
        ];
    }
}
