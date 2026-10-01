<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyReward;
use Modules\Support\Enums\DateTimeFormat;

class RewardsByTierReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_rewards.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyReward::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_rewards_by_tier";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "tier_name",
            "reward_name",
            "points_cost",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "lt.name as tier_name",
            "loyalty_rewards.name as reward_name",
            "loyalty_rewards.points_cost",
            "MIN(loyalty_rewards.created_at) as start_date",
            "MAX(loyalty_rewards.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "tier_name" => $model->tier_name,
            "reward_name" => $model->reward_name,
            "points_cost" => $model->points_cost . " Pts",
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->leftJoin('loyalty_tiers as lt', 'lt.id', '=', 'loyalty_rewards.loyalty_tier_id')
                ->whereNotNull('loyalty_rewards.loyalty_tier_id')
        ];
    }
}
