<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Report\Models\WaiterDailyCollection;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class WaiterCollectionReport extends Report
{
    public function key(): string
    {
        return 'waiter_collection';
    }

    public function model(): string
    {
        return WaiterDailyCollection::class;
    }

    public function attributes(): Collection
    {
        return collect([
            'period',
            'waiter',
            'orders_served',
            'tables_served',
            'sales_total',
            'collection_total',
            'cash_total',
            'upi_total',
            'card_total',
            'tips_total',
            'pending_total',
            'average_bill_value',
        ]);
    }

    public function columns(): array
    {
        return [
            'waiter_id',
            'MIN(business_date) as start_date',
            'MAX(business_date) as end_date',
            'MAX(currency) as currency',
            'SUM(orders_served) as orders_served',
            'SUM(tables_served) as tables_served',
            'SUM(sales_total) as sales_total',
            'SUM(collection_total) as collection_total',
            'SUM(cash_total) as cash_total',
            'SUM(upi_total) as upi_total',
            'SUM(card_total) as card_total',
            'SUM(tips_total) as tips_total',
            'SUM(pending_total) as pending_total',
            'CASE WHEN SUM(orders_served) > 0 THEN SUM(sales_total) / SUM(orders_served) ELSE 0 END as average_bill_value',
        ];
    }

    public function resource(Model $model): array
    {
        $currency = $model->currency ?: $this->currency;

        return [
            'period' => dateTimeFormat($model->start_date, DateTimeFormat::Date) . ' - ' . dateTimeFormat($model->end_date, DateTimeFormat::Date),
            'waiter' => $model->waiter?->name ?: __('report::reports.unassigned'),
            'orders_served' => (int) $model->orders_served,
            'tables_served' => (int) $model->tables_served,
            'sales_total' => new Money((float) $model->sales_total, $currency),
            'collection_total' => new Money((float) $model->collection_total, $currency),
            'cash_total' => new Money((float) $model->cash_total, $currency),
            'upi_total' => new Money((float) $model->upi_total, $currency),
            'card_total' => new Money((float) $model->card_total, $currency),
            'tips_total' => new Money((float) $model->tips_total, $currency),
            'pending_total' => new Money((float) $model->pending_total, $currency),
            'average_bill_value' => new Money((float) $model->average_bill_value, $currency),
        ];
    }

    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)->groupBy('waiter_id'),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            [
                'key' => 'waiter_id',
                'label' => __('report::reports.filters.waiter'),
                'type' => 'select',
                'options' => User::query()
                    ->withOutGlobalBranchPermission()
                    ->role(DefaultRole::Waiter->value)
                    ->select('id', 'name')
                    ->orderBy('name')
                    ->get()
                    ->map(fn(User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ])
                    ->all(),
            ],
        ];
    }

    public function with(): array
    {
        return [
            'waiter:id,name',
        ];
    }
}
