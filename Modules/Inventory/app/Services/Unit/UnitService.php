<?php

namespace Modules\Inventory\Services\Unit;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Inventory\Enums\UnitType;
use Modules\Inventory\Models\Unit;
use Modules\Support\GlobalStructureFilters;

class UnitService implements UnitServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("inventory::units.unit");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->filters($filters, [])
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Unit
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Unit::class;
    }

    /** @inheritDoc */
    public function show(int $id): Unit
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Unit
    {
        return $this->getModel()->query()->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Unit
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Unit
    {
        $unit = $this->findOrFail($id);
        $unit->update($data);

        return $unit;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()->query()->whereIn("id", parseIds($ids))->delete() ?: false;
    }

    /** @inheritDoc */
    public function getStructureFilters(): array
    {
        return [
            [
                "key" => 'type',
                "label" => __('inventory::units.filters.type'),
                "type" => 'select',
                "options" => UnitType::toArrayTrans(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(): array
    {
        return [
            "types" => UnitType::toArrayTrans(),
        ];
    }
}
