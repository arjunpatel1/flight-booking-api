<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyReward;

class RewardsByTypeReport extends LoyaltyReport
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
        return "loyalty_rewards_by_type";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "reward_type",
            "total_rewards",
            "total_redemptions",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_rewards.type as type",
            "COUNT(DISTINCT loyalty_rewards.id) as total_rewards",
            "COALESCE(SUM(CASE WHEN loyalty_gifts.used_at IS NOT NULL THEN 1 ELSE 0 END), 0) as total_redemptions",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "reward_type" => $model->type?->trans() ?? $model->type,
            "total_rewards" => (int)$model->total_rewards,
            "total_redemptions" => (int)$model->total_redemptions,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->leftJoin('loyalty_gifts', 'loyalty_gifts.loyalty_reward_id', '=', 'loyalty_rewards.id')
                ->groupBy('loyalty_rewards.type')
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
            $this->programFilter(),
        ];
    }
}
