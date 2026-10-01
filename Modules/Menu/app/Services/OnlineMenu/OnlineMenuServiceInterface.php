<?php

namespace Modules\Menu\Services\OnlineMenu;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Menu\Models\OnlineMenu;

interface OnlineMenuServiceInterface
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
     * @return OnlineMenu
     */
    public function getModel(): OnlineMenu;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return OnlineMenu|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): OnlineMenu|Builder|EloquentCollection|array;

    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return OnlineMenu
     * @throws ModelNotFoundException
     */
    public function show(int $id): OnlineMenu;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return OnlineMenu
     */
    public function store(array $data): OnlineMenu;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return OnlineMenu
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): OnlineMenu;

    /**
     * Destroy resource's by given id.
     *
     * @param int|array|string $ids
     * @return bool
     * @throws ModelNotFoundException
     * @throws LogicException
     */
    public function destroy(int|array|string $ids): bool;

    /**
     * Get structure filters for frontend
     *
     * @param int|null $branchId
     * @return array
     */
    public function getStructureFilters(?int $branchId): array;

    /**
     * Get form meta
     *
     * @param int|null $branchId
     * @return array
     */
    public function getFormMeta(?int $branchId): array;

    /**
     * Get menu data
     *
     * @param string $slug
     * @return array
     */
    public function getMenu(string $slug): array;
}
