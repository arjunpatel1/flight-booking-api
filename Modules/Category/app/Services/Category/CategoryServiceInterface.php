<?php

namespace Modules\Category\Services\Category;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Category\Models\Category;

interface CategoryServiceInterface
{
    /**
     * Label for the resource.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return Category
     */
    public function getModel(): Category;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return Category|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Category|Builder|EloquentCollection|array;

    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @return Collection
     */
    public function getForTree(array $filters = []): Collection;

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return Category
     * @throws ModelNotFoundException
     */
    public function show(int $id): Category;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Category
     */
    public function store(array $data): Category;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return Category
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Category;

    /**
     * Destroy resource's by given id.
     *
     * @param int|array|string $ids
     * @return bool
     * @throws ModelNotFoundException
     * @throws LogicException
     */
    public function destroy(int|array|string $ids): bool;
}
