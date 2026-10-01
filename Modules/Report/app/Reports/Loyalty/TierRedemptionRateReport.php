<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Support\Enums\DateTimeFormat;

class TierRedemptionRateReport extends LoyaltyReport
{
    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_tier_redemption_rate";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "tier_name",
            "earned_points",
            "redeemed_points",
            "redemption_rate",
            "period",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "lt.id as tier_id",
            "lt.name as tier_name",
            "COALESCE(SUM(CASE WHEN loyalty_transactions.type in ('earn','bonus','adjust') AND loyalty_transactions.points > 0 THEN loyalty_transactions.points ELSE 0 END), 0) as earned_points",
            "COALESCE(SUM(CASE WHEN loyalty_transactions.type = 'redeem' THEN loyalty_transactions.points ELSE 0 END), 0) as redeemed_points",
            "MIN(loyalty_transactions.created_at) as start_date",
            "MAX(loyalty_transactions.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyCustomer::class;
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $earned = (int)$model->earned_points;
        $redeemed = (int)$model->redeemed_points;
        $rate = $earned > 0 ? round(($redeemed / $earned) * 100, 2) : 0;

        return [
            "tier_name" => $model->tier_name,
            "earned_points" => $earned,
            "redeemed_points" => abs($redeemed),
            "redemption_rate" => abs($rate) . "%",
            "period" => ($model->start_date && $model->end_date)
                ? dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date)
                : null,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_tiers as lt', 'lt.id', '=', 'loyalty_customers.loyalty_tier_id')
                ->leftJoin('loyalty_transactions', 'loyalty_transactions.loyalty_customer_id', '=', 'loyalty_customers.id')
                ->groupBy('lt.id', 'lt.name')
        ];
    }
}
