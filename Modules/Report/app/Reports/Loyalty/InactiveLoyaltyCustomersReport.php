<?php

namespace Modules\Report\Reports\Loyalty;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\GlobalStructureFilters;

class InactiveLoyaltyCustomersReport extends LoyaltyReport
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
        return "loyalty_inactive_customers";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "customer_name",
            "last_activity_date",
            "days_inactive",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_customers.id as loyalty_customer_id",
            "customers.name as customer_name",
            "COALESCE(last_tx.last_tx_date, loyalty_customers.last_earned_at, loyalty_customers.last_redeemed_at, loyalty_customers.created_at) as last_activity_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        $lastActivity = $model->last_activity_date ? Carbon::parse($model->last_activity_date) : null;
        $daysInactive = $lastActivity?->diffInDays(now());

        return [
            "customer_name" => $model->customer_name,
            "last_activity_date" => $lastActivity ? dateTimeFormat($lastActivity, DateTimeFormat::Date) : null,
            "days_inactive" => round($daysInactive, 2),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('users as customers', 'customers.id', '=', 'loyalty_customers.customer_id')
                ->leftJoin(
                    DB::raw('(SELECT loyalty_customer_id, MAX(created_at) as last_tx_date FROM loyalty_transactions GROUP BY loyalty_customer_id) as last_tx'),
                    'last_tx.loyalty_customer_id',
                    '=',
                    'loyalty_customers.id'
                )
                ->groupBy(
                    'loyalty_customers.customer_id',
                    'last_tx.last_tx_date',
                    'loyalty_customers.last_earned_at',
                    'loyalty_customers.last_redeemed_at',
                    'loyalty_customers.created_at'
                )
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
