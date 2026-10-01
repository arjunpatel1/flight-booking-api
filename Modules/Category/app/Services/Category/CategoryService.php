<?php

namespace Modules\Category\Services\Category;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Category\Models\Category;

class CategoryService implements CategoryServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("category::categories.category");
    }

    /** @inheritDoc */
    public function getForTree(array $filters = []): Collection
    {
        return $this->getModel()
            ->withoutGlobalActive()
            ->with('files')
            ->orderBy('order')
            ->filters($filters)
            ->get();
    }

    /** @inheritDoc */
    public function getModel(): Category
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Category::class;
    }

    /** @inheritDoc */
    public function show(int $id): Category
    {
        return $this->getModel()
            ->withoutGlobalActive()
            ->with('files')
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|Category
    {
        return $this->getModel()
            ->withoutGlobalActive()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Category
    {
        return $this->getModel()->create($data);
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->withoutGlobalActive()
            ->whereIn("id", parseIds($ids))
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Category
    {
        $category = $this->findOrFail($id);
        $category->update($data);

        return $category;
    }
}
