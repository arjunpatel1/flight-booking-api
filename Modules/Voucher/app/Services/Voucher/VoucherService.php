<?php

namespace Modules\Voucher\Services\Voucher;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Category\Models\Category;
use Modules\Order\Enums\OrderType;
use Modules\Product\Models\Product;
use Modules\Support\Enums\Day;
use Modules\Support\Enums\PriceType;
use Modules\Support\GlobalStructureFilters;
use Modules\Voucher\Models\Voucher;

class VoucherService implements VoucherServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("voucher::vouchers.voucher");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with("branch:id,name")
            ->withoutGlobalActive()
            ->whereNull("meta->reward_id")
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Voucher
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Voucher::class;
    }

    /** @inheritDoc */
    public function show(int $id): Voucher
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Voucher
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->whereNull("meta->reward_id")
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Voucher
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Voucher
    {
        $voucher = $this->findOrFail($id);
        $voucher->update($data);

        return $voucher;
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
                "label" => __('voucher::vouchers.filters.type'),
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
