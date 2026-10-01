<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\Support\Enums\DateTimeFormat;

class RedemptionRateReport extends LoyaltyReport
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
        return "loyalty_redemption_rate";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "program_name",
            "earned_points",
            "redeemed_points",
            "redemption_rate",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "lp.name as program_name",
            "COALESCE(SUM(CASE WHEN loyalty_transactions.type in ('earn','bonus','adjust') AND loyalty_transactions.points > 0 THEN loyalty_transactions.points ELSE 0 END), 0) as earned_points",
            "COALESCE(SUM(CASE WHEN loyalty_transactions.type = 'redeem' THEN loyalty_transactions.points ELSE 0 END), 0) as redeemed_points",
            "MIN(loyalty_transactions.created_at) as start_date",
            "MAX(loyalty_transactions.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $earned = (int)$model->earned_points;
        $redeemed = abs((int)$model->redeemed_points);
        $rate = $earned > 0 ? round(($redeemed / $earned) * 100, 2) : 0;

        return [
            "program_name" => $model->program_name,
            "earned_points" => $earned,
            "redeemed_points" => $redeemed,
            "redemption_rate" => abs($rate) . "%",
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
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
                ->groupBy('lp.id')
        ];
    }
}
