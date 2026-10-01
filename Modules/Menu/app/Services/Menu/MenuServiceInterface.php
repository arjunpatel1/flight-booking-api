<?php

namespace Modules\Menu\Services\Menu;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Menu\Models\Menu;

interface MenuServiceInterface
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
     * @return Menu
     */
    public function getModel(): Menu;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return Menu|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Menu|Builder|EloquentCollection|array;

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
     * @return Menu
     * @throws ModelNotFoundException
     */
    public function show(int $id): Menu;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Menu
     */
    public function store(array $data): Menu;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return Menu
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Menu;

    public function copyToBranch(int $id, int $branchId, ?string $name = null, bool $activate = false): Menu;

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
     * @return array
     */
    public function getStructureFilters(): array;

    /**
     * Get form meta
     *
     * @return array
     */
    public function getFormMeta(): array;

    /**
     * Get current menu
     *
     * @param int|null $menuId
     * @param bool $withBranch
     * @return Menu|null
     */
    public function getCurrentMenu(?int $menuId = null, bool $withBranch = false): ?Menu;
}
