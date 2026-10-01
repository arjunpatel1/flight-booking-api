<?php

namespace Modules\Report\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Category\Models\Category;
use Modules\Menu\Models\Menu;
use Modules\Report\Report;
use Modules\Support\GlobalStructureFilters;

class CategorizedProductsReport extends Report
{
    /** @inheritDoc */
    public function key(): string
    {
        return "categorized_products";
    }

    /** @inheritDoc */
    public function attributes(): Collection
    {
        return collect([
            "category",
            "products_count",
        ]);
    }

    /** @inheritDoc */
    public function columns(): array
    {
        return [
            "id",
            "name"
        ];
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Category::class;
    }

    /** @inheritDoc */
    public function resource(Model $model): array
    {
        return [
            "category" => $model->name,
            "products_count" => $model->products_count ?: "0"
        ];
    }

    /** @inheritDoc */
    public function through(Request $request): array
    {
        return [
            fn(Builder $query, Closure $next) => $next($query)
                ->withoutGlobalActive()
                ->withCount("products")
                ->orderByDesc('products_count'),
        ];
    }

    /** @inheritDoc */
    public function filters(Request $request): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        $filters = $request->get('filters', []);
        $branchId = $filters['branch_id'] ?? null;

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'menu_id',
                "label" => __('report::reports.filters.menu'),
                "type" => 'select',
                "options" => !is_null($branchId) ? Menu::list($branchId, true) : [],
                "depends" => "branch_id"
            ],
            GlobalStructureFilters::active()
        ];
    }

    /**
     * Get global filters
     *
     * @return array
     */
    public function globalFilters(): array
    {
        return [];
    }

    /** @inheritDoc */
    public function hasSearch(): bool
    {
        return true;
    }
}
