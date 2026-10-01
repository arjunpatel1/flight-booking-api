<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Support\GlobalStructureFilters;

class AvailableGiftsReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_gifts.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyGift::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_available_gifts";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "gift_id",
            "customer_name",
            "reward_name",
            "valid_from",
            "valid_until",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_gifts.id as gift_id",
            "customers.name as customer_name",
            "lr.name as reward_name",
            "loyalty_gifts.valid_from",
            "loyalty_gifts.valid_until",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "gift_id" => (int)$model->gift_id,
            "customer_name" => $model->customer_name,
            "reward_name" => $model->reward_name,
            "valid_from" => dateTimeFormat($model->valid_from),
            "valid_until" => $model->valid_until,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('users as customers', 'customers.id', '=', 'loyalty_gifts.loyalty_customer_id')
                ->join('loyalty_rewards as lr', 'lr.id', '=', 'loyalty_gifts.loyalty_reward_id')
                ->where('loyalty_gifts.status', 'available')
                ->whereNull('loyalty_gifts.used_at')
        ];
    }

    /** @inheritDoc */
    public function globalFilters(): array
    {
        return [
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
            $this->programFilter(),
        ];
    }
}
