<?php

namespace Modules\User\Services\Customer;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use LogicException;
use Modules\User\Models\User;

interface CustomerServiceInterface
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
     * @return User
     */
    public function getModel(): User;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return User|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): User|Builder|EloquentCollection|array;

    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Get engagement summary used by CRM and marketing operations.
     *
     * @return array
     */
    public function engagementSummary(): array;

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return User
     * @throws ModelNotFoundException
     */
    public function show(int $id): User;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return User
     */
    public function store(array $data): User;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return User
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): User;

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
     * Search customers
     *
     * @param string|null $query
     * @return Collection
     */
    public function search(?string $query = null): Collection;

    /**
     * Store a minimal customer from POS screen.
     *
     * @param array $data
     * @return User
     */
    public function quickStore(array $data): User;

    /**
     * Show detailed customer with orders, loyalty, feedback.
     *
     * @param int $id
     * @return array
     */
    public function showDetail(int $id): array;
}
