<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyCustomer;

class NoRedemptionsReport extends LoyaltyReport
{
    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyCustomer::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_no_redemptions";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "customer_name",
            "lifetime_points",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_customers.id as loyalty_customer_id",
            "customers.name as customer_name",
            "loyalty_customers.lifetime_points",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "customer_name" => $model->customer_name,
            "lifetime_points" => (int)$model->lifetime_points,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('users as customers', 'customers.id', '=', 'loyalty_customers.customer_id')
                ->leftJoin('loyalty_transactions', 'loyalty_transactions.loyalty_customer_id', '=', 'loyalty_customers.id')
                ->groupBy('loyalty_customers.customer_id')
                ->havingRaw("COALESCE(SUM(CASE WHEN loyalty_transactions.type = 'redeem' THEN 1 ELSE 0 END), 0) = 0")
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
