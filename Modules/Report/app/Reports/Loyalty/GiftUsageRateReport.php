<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyGift;

class GiftUsageRateReport extends LoyaltyReport
{
    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_gift_usage_rate";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "reward_name",
            "issued_count",
            "used_count",
            "usage_rate",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "lr.name as reward_name",
            "COUNT(loyalty_gifts.id) as issued_count",
            "COALESCE(SUM(CASE WHEN loyalty_gifts.status = 'used' THEN 1 ELSE 0 END), 0) as used_count",
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyGift::class;
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $issued = (int)$model->issued_count;
        $used = (int)$model->used_count;
        $rate = $issued > 0 ? round(($used / $issued) * 100, 2) : 0;

        return [
            "reward_name" => $model->reward_name,
            "issued_count" => $issued,
            "used_count" => $used,
            "usage_rate" => round($rate, 2) . "%",
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_rewards as lr', 'lr.id', '=', 'loyalty_gifts.loyalty_reward_id')
                ->groupBy('lr.id')
        ];
    }

    /** @inheritDoc */
    public function globalFilters(): array
    {
        return [
            $this->programFilter(),
        ];
    }
}
