<?php

namespace Modules\Discount\Services\Discount;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Discount\Models\Discount;
use Modules\Order\Enums\OrderType;
use Modules\Product\Models\Product;
use Modules\Support\Enums\Day;
use Modules\Support\Enums\PriceType;
use Modules\Support\GlobalStructureFilters;

class DiscountService implements DiscountServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("discount::discounts.discount");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with("branch:id,name")
            ->withoutGlobalActive()
            ->filters($filters)
            ->whereNull("meta->reward_id")
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Discount
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Discount::class;
    }

    /** @inheritDoc */
    public function show(int $id): Discount
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Discount
    {
        return $this->getModel()
            ->query()
            ->whereNull("meta->reward_id")
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Discount
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Discount
    {
        $discount = $this->findOrFail($id);
        $discount->update($data);

        return $discount;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->whereNull("meta->reward_id")
            ->whereIn("id", parseIds($ids))
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'type',
                "label" => __('discount::discounts.filters.type'),
                "type" => 'select',
                "options" => PriceType::toArrayTrans(),
            ],
            GlobalStructureFilters::active(),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(): array
    {
        return [
            "branches" => Branch::list(),
            "days" => Day::toArrayTrans(),
            "types" => PriceType::toArrayTrans(),
            "orderTypes" => OrderType::toArrayTrans(),
            "categories" => Category::treeListWithSlug(),
            "products" => Product::listBySku(),
        ];
    }
}
