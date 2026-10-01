<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Support\GlobalStructureFilters;

class TopCustomersByPointsReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_customers.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyCustomer::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_top_customers_by_points";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "customer_name",
            "lifetime_points",
            "points_balance",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_customers.id as loyalty_customer_id",
            "customers.name as customer_name",
            "loyalty_customers.lifetime_points",
            "loyalty_customers.points_balance",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "customer_name" => $model->customer_name,
            "lifetime_points" => (int)$model->lifetime_points,
            "points_balance" => (int)$model->points_balance,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('users as customers', 'customers.id', '=', 'loyalty_customers.customer_id')
                ->groupBy(
                    'loyalty_customers.customer_id',
                    'loyalty_customers.lifetime_points',
                    'loyalty_customers.points_balance'
                )
                ->orderByDesc('loyalty_customers.lifetime_points')
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
