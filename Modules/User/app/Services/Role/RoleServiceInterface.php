<?php

namespace Modules\User\Services\Role;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\User\Models\Role;
use Spatie\Permission\Exceptions\RoleAlreadyExists;

interface RoleServiceInterface
{
    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array|null $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return Role
     * @throws ModelNotFoundException
     */
    public function show(int $id): Role;

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
     * @return Role
     */
    public function getModel(): Role;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return Role|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Role|Builder|EloquentCollection|array;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return Role|Builder|EloquentCollection|array|null;
     */
    public function find(int $id): Role|Builder|EloquentCollection|array|null;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Role
     * @throws RoleAlreadyExists
     */
    public function store(array $data): Role;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return Role
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Role;

    /**
     * Destroy resource's by given id.
     *
     * @param int|string|array $ids
     * @return bool
     * @throws ModelNotFoundException
     * @throws LogicException
     */
    public function destroy(int|string|array $ids): bool;

    /**
     * Get permissions
     *
     * @return array
     */
    public function getPermissions(): array;

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
     * Sync permissions for a role.
     *
     * @param int $id
     * @param array $permissionNames
     * @return Role
     */
    public function syncPermissions(int $id, array $permissionNames): Role;

    /**
     * Get all permissions grouped.
     *
     * @return array
     */
    public function getPermissionTree(): array;
}
