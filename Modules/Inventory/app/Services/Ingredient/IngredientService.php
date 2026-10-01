<?php

namespace Modules\Inventory\Services\Ingredient;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\Ingredient;
use Modules\Inventory\Models\Unit;
use Modules\Support\GlobalStructureFilters;

class IngredientService implements IngredientServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("inventory::ingredients.ingredient");
    }

    /** @inheritDoc */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["branch:id,name,currency", "unit:id,name"])
            ->filters($filters, [])
            ->sortBy($sorts)
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Ingredient
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Ingredient::class;
    }

    /** @inheritDoc */
    public function show(int $id): Ingredient
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Ingredient
    {
        return $this->getModel()->query()->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Ingredient
    {
        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Ingredient
    {
        $ingredient = $this->findOrFail($id);
        $ingredient->update($data);

        return $ingredient;
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
            [
                "key" => 'unit_id',
                "label" => __('inventory::ingredients.filters.unit'),
                "type" => 'select',
                "options" => Unit::list(),
            ],
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
        ];
    }

    /** @inheritDoc */
    public function getFormMeta(): array
    {
        return [
            "units" => Unit::list(),
            "branches" => Branch::list(),
        ];
    }
}
