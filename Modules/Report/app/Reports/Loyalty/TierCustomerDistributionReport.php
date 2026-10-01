<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Support\Enums\DateTimeFormat;

class TierCustomerDistributionReport extends LoyaltyReport
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
        return "loyalty_tier_customer_distribution";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "tier_name",
            "customers_count",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "lt.id as tier_id",
            "lt.name as tier_name",
            "COUNT(DISTINCT loyalty_customers.id) as customers_count",
            "MIN(loyalty_customers.created_at) as start_date",
            "MAX(loyalty_customers.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "tier_name" => $model->tier_name,
            "customers_count" => (int)$model->customers_count,
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_tiers as lt', 'lt.id', '=', 'loyalty_customers.loyalty_tier_id')
                ->groupBy('lt.id')
        ];
    }
}
