<?php

namespace Modules\Report\Reports\Loyalty;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Enums\LoyaltyGiftStatus;
use Modules\Loyalty\Models\LoyaltyGift;
use Modules\Support\GlobalStructureFilters;

class ExpiredGiftsReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_gifts.valid_until";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyGift::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_expired_gifts";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "gift_id",
            "customer_name",
            "reward_name",
            "expiration_date",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_gifts.id as gift_id",
            "customers.name as customer_name",
            "lr.name as reward_name",
            "loyalty_gifts.valid_until as expiration_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "gift_id" => (int)$model->gift_id,
            "customer_name" => $model->customer_name,
            "reward_name" => $model->reward_name,
            "expiration_date" => dateTimeFormat(Carbon::parse($model->expiration_date)),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('users as customers', 'customers.id', '=', 'loyalty_gifts.loyalty_customer_id')
                ->join('loyalty_rewards as lr', 'lr.id', '=', 'loyalty_gifts.loyalty_reward_id')
                ->where('loyalty_gifts.status', LoyaltyGiftStatus::Expired)
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
