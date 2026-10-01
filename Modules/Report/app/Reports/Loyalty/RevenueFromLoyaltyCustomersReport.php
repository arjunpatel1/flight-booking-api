<?php

namespace Modules\Report\Reports\Loyalty;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Loyalty\Enums\LoyaltyTransactionType;
use Modules\Loyalty\Models\LoyaltyTransaction;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class RevenueFromLoyaltyCustomersReport extends LoyaltyReport
{
    /** @inheritDoc */
    protected function resolveDefaultDateColumn(): void
    {
        $this->model()::$defaultDateColumn = "loyalty_transactions.created_at";
    }

    /** @inheritDoc */
    public function model(): string
    {
        return LoyaltyTransaction::class;
    }

    /** @inheritDoc */
    public function key(): string
    {
        return "loyalty_revenue_from_loyalty_customers";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "period",
            "revenue",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "COALESCE(SUM(CASE WHEN loyalty_transactions.type = '" . LoyaltyTransactionType::Earn->value . "' THEN loyalty_transactions.amount ELSE 0 END), 0) as revenue_raw",
            "MIN(loyalty_transactions.created_at) as start_date",
            "MAX(loyalty_transactions.created_at) as end_date",
        ];
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "period" => dateTimeFormat($model->start_date, DateTimeFormat::Date) . " - " . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            "revenue" => Money::inDefaultCurrency($model->revenue_raw),
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        $filters = $request->get('filters', []);

        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->join('loyalty_customers as lc', 'lc.id', '=', 'loyalty_transactions.loyalty_customer_id')
                ->where('loyalty_transactions.type', LoyaltyTransactionType::Earn->value)
                ->when(isset($filters['loyalty_program_id']), fn($q) => $q->where('lc.loyalty_program_id', $filters['loyalty_program_id']))
        ];
    }
}
