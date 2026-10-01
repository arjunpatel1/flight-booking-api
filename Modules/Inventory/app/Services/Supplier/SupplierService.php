<?php

namespace Modules\Inventory\Services\Supplier;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\Supplier;
use Modules\Support\GlobalStructureFilters;

class SupplierService implements SupplierServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("inventory::suppliers.supplier");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["branch:id,name"])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Supplier
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Supplier::class;
    }

    /** @inheritDoc */
    public function show(int $id): Supplier
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Supplier
    {
        return $this->getModel()->query()->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Supplier
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Supplier
    {
        $supplier = $this->findOrFail($id);
        $supplier->update($data);

        return $supplier;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()->query()->whereIn("id", parseIds($ids))->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        $branchFilter = GlobalStructureFilters::branch();
        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(): array
    {
        return [
            "branches" => Branch::list(),
        ];
    }
}
