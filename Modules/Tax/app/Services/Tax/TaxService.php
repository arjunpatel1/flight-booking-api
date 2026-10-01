<?php

namespace Modules\Tax\Services\Tax;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Order\Enums\OrderType;
use Modules\Support\GlobalStructureFilters;
use Modules\Tax\Enums\TaxType;
use Modules\Tax\Models\Tax;

class TaxService implements TaxServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("tax::taxes.tax");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->filters($filters, [])
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Tax
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Tax::class;
    }

    /** @inheritDoc */
    public function show(int $id): Tax
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Tax
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Tax
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Tax
    {
        $tax = $this->findOrFail($id);

        $tax->update($data);

        return $tax;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->withoutGlobalActive()
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
                "label" => __('tax::taxes.filters.type'),
                "type" => 'select',
                "options" => TaxType::toArrayTrans()
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
            "types" => TaxType::toArrayTrans(),
            "order_types" => OrderType::toArrayTrans(),
        ];
    }
}
