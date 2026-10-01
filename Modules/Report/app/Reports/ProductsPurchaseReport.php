<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Category\Models\Category;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\OrderProduct;
use Modules\Report\Report;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Support\Money;

class ProductsPurchaseReport extends Report
{
    /** {@inheritDoc} */
    public function model(): string
    {
        return OrderProduct::class;
    }

    /** {@inheritDoc} */
    public function key(): string
    {
        return 'products_purchase';
    }

    /** {@inheritDoc} */
    public function attributes(): Collection
    {
        return collect([
            'period',
            'product',
            'quantity',
            'total',
        ]);
    }

    /** {@inheritDoc} */
    public function columns(): array
    {
        $rate = $this->withRate ? 'currency_rate' : '1';

        return [
            'product_id',
            'MIN(created_at) as start_date',
            'MAX(created_at) as end_date',
            'SUM(quantity) as quantity',
            "SUM(total * $rate) as total",
        ];
    }

    /** {@inheritDoc} */
    public function with(): array
    {
        return ['product' => fn ($query) => $query->select('id', 'name')->without(['branch'])];
    }

    /** {@inheritDoc} */
    public function resource(Model $model): array
    {
        return [
            'period' => dateTimeFormat($model->start_date, DateTimeFormat::Date).' - '.dateTimeFormat($model->end_date, DateTimeFormat::Date),
            'product' => $model->name,
            'quantity' => $model->quantity,
            'total' => new Money($model->total->amount(), $this->currency),
        ];
    }

    /** {@inheritDoc} */
    public function through(Request $request): array
    {
        return [
            fn (Builder $query, Closure $next) => $next($query)
                ->when($this->user->assignedToBranch(), fn (Builder $query) => $query->branchId($this->user->branch_id))
                ->without(['product', 'taxes', 'options'])
                ->groupBy('product_id'),
        ];
    }

    /**
     * Order-product rows inherit ownership from their parent order, rather
     * than carrying a tenant or branch column themselves. The generic report
     * guard correctly rejects unknown ownership shapes, but that made this
     * report return an empty result for every tenant administrator.
     */
    protected function scopeToAuthenticatedTenant(Builder $query): Builder
    {
        if (! $this->user?->assignedToTenant()) {
            return $query;
        }

        return $query->whereHas('order.branch', fn (Builder $branch) => $branch
            ->where('tenant_id', $this->user->tenant_id));
    }

    /** {@inheritDoc} */
    public function filters(Request $request): array
    {
        $filters = $request->get('filters', []);
        $branchId = $this->user?->assignedToBranch()
            ? $this->user->branch_id
            : ($filters['branch_id'] ?? null);
        $categories = Category::query()
            ->select('categories.id', 'categories.name')
            ->withoutGlobalActive()
            ->when($branchId, fn (Builder $query) => $query
                ->whereHas('menu', fn (Builder $menu) => $menu->where('branch_id', $branchId)))
            ->orderBy('categories.order')
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->values();

        return [
            [
                'key' => 'category_id',
                'label' => __('report::reports.filters.category'),
                'type' => 'select',
                'options' => $categories,
                ...($this->user?->assignedToBranch() ? [] : ['depends' => 'branch_id']),
            ],
            [
                'key' => 'status',
                'label' => __('report::reports.filters.order_status'),
                'type' => 'select',
                'options' => OrderStatus::toArrayTrans(),
            ],
            [
                'key' => 'type',
                'label' => __('report::reports.filters.order_type'),
                'type' => 'select',
                'options' => OrderType::toArrayTrans(),
            ],
            [
                'key' => 'payment_status',
                'label' => __('report::reports.filters.payment_status'),
                'type' => 'select',
                'options' => OrderPaymentStatus::toArrayTrans(),
            ],
        ];
    }

    /** {@inheritDoc} */
    public function hasSearch(): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    protected function summary(Request $request, array $data): array
    {
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $this->data($request, false);

        return [
            ...parent::summary($request, $data),
            [
                'key' => 'filtered_quantity',
                'label' => __('report::reports.filtered_quantity'),
                'value' => (int) $rows->sum('quantity'),
                'icon' => 'tabler-packages',
                'color' => 'info',
            ],
            [
                'key' => 'filtered_value',
                'label' => __('report::reports.filtered_value'),
                'value' => new Money(
                    (float) $rows->sum(fn (array $row) => $row['total']->amount()),
                    $this->currency,
                ),
                'icon' => 'tabler-cash',
                'color' => 'primary',
            ],
        ];
    }
}
