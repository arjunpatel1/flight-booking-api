<?php

namespace Modules\Report\Reports\Loyalty;

use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Enums\LoyaltyTransactionType;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\GlobalStructureFilters;

class LoyaltyLastActivityReport extends LoyaltyReport
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
        return "loyalty_last_activity";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "customer_name",
            "last_earned_date",
            "last_redeemed_date",
            "last_transaction_type",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "loyalty_customers.id as loyalty_customer_id",
            "customers.name as customer_name",
            "MAX(CASE WHEN loyalty_transactions.type in ('earn','bonus','adjust') THEN loyalty_transactions.created_at END) as last_earned_date",
            "MAX(CASE WHEN loyalty_transactions.type = 'redeem' THEN loyalty_transactions.created_at END) as last_redeemed_date",
            "last_tx.last_tx_date as last_activity_date",
            "last_lt.type as last_transaction_type",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "customer_name" => $model->customer_name,
            "last_earned_date" => $model->last_earned_date
                ? dateTimeFormat(Carbon::parse($model->last_earned_date), DateTimeFormat::Date)
                : null,
            "last_redeemed_date" => $model->last_redeemed_date
                ? dateTimeFormat(Carbon::parse($model->last_redeemed_date), DateTimeFormat::Date)
                : null,
            "last_transaction_type" => LoyaltyTransactionType::from($model->last_transaction_type)->trans(),
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
                ->leftJoin(
                    DB::raw('(SELECT loyalty_customer_id, MAX(created_at) as last_tx_date FROM loyalty_transactions GROUP BY loyalty_customer_id) as last_tx'),
                    'last_tx.loyalty_customer_id',
                    '=',
                    'loyalty_customers.id'
                )
                ->leftJoin('loyalty_transactions as last_lt', function ($join) {
                    $join->on('last_lt.loyalty_customer_id', '=', 'loyalty_customers.id')
                        ->on('last_lt.created_at', '=', 'last_tx.last_tx_date');
                })
                ->groupBy(
                    'loyalty_customers.customer_id',
                    'last_tx.last_tx_date',
                    'last_lt.type'
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
