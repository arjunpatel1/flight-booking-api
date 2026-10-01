<?php

namespace Modules\Inventory\Services\StockMovement;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\StockMovement;
use Modules\Support\GlobalStructureFilters;

class StockMovementService implements StockMovementServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("inventory::stock_movements.stock_movement");
    }

    /** @inheritDoc */
    public function show(int $id): StockMovement
    {
        return $this->getModel()
            ->query()
            ->with(["branch:id,name", "ingredient:id,name,unit_id"])
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|StockMovement
    {
        return $this->getModel()->query()->findOrFail($id);
    }

    /** @inheritDoc */
    public function getModel(): StockMovement
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return StockMovement::class;
    }

    /** @inheritDoc */
    public function store(array $data): StockMovement
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): StockMovement
    {
        $stockMovement = $this->findOrFail($id);
        $stockMovement->update($data);

        return $stockMovement;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        $models = $this->getModel()
            ->with("ingredient")
            ->whereIn('id', parseIds($ids))
            ->get();

        if ($models->isEmpty()) {
            return false;
        }

        $models->each(function (StockMovement $stockMovement) {
            $stockMovement->delete();
        });

        return true;
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with([
                "ingredient:id,name,unit_id",
                "branch:id,name",
            ])
            ->filters($filters)
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getStructureFilters(?int $branchId = null): array
    {
        $branchFilter = GlobalStructureFilters::branch();

        return [
            ...(is_null($branchFilter) ? [] : [$branchFilter]),
            [
                "key" => 'type',
                "label" => __('inventory::stock_movements.filters.type'),
                "type" => 'select',
                "options" => StockMovementType::toArrayTrans(),
            ],
            [
                "key" => 'ingredient_id',
                "label" => __('inventory::stock_movements.filters.ingredient'),
                "type" => 'select',
                "options" => !is_null($branchId) ? Ingredient::list($branchId) : [],
                "depends" => "branch_id"
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(?int $branchId = null): array
    {
        if (is_null($branchId)) {
            return [
                "branches" => Branch::list(),
                "types" => StockMovementType::toArrayTrans(),
            ];
        } else {
            return [
                "ingredients" => Ingredient::list($branchId),
            ];
        }
    }
}
