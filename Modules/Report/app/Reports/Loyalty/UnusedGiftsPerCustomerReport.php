<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Enums\LoyaltyGiftStatus;
use Modules\Loyalty\Models\LoyaltyGift;

class UnusedGiftsPerCustomerReport extends LoyaltyReport
{
    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_unused_gifts_per_customer";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "customer_name",
            "unused_gifts_count",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "customers.name as customer_name",
            "COUNT(loyalty_gifts.id) as unused_gifts_count",
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
        return [
            "customer_name" => $model->customer_name,
            "unused_gifts_count" => (int)$model->unused_gifts_count,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_customers as lc', 'lc.id', '=', 'loyalty_gifts.loyalty_customer_id')
                ->join('users as customers', 'customers.id', '=', 'lc.customer_id')
                ->where('loyalty_gifts.status', LoyaltyGiftStatus::Available->value)
                ->whereNull('loyalty_gifts.used_at')
                ->groupBy('lc.id', 'customers.id')
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
