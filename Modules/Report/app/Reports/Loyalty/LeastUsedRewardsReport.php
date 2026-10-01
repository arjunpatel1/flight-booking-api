<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Support\Enums\DateTimeFormat;

class LeastUsedRewardsReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_gifts.used_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyGift::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_least_used_rewards";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "reward_name",
            "total_redemptions",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_gifts.loyalty_reward_id",
            "lr.name as reward_name",
            "COUNT(loyalty_gifts.id) as total_redemptions",
            "MIN(loyalty_gifts.used_at) as start_date",
            "MAX(loyalty_gifts.used_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "reward_name" => $model->reward_name,
            "total_redemptions" => (int)$model->total_redemptions,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_rewards as lr', 'lr.id', '=', 'loyalty_gifts.loyalty_reward_id')
                ->whereNotNull('loyalty_gifts.used_at')
                ->groupBy('loyalty_gifts.loyalty_reward_id')
                ->orderBy('total_redemptions')
        ];
    }
}
