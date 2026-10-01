<?php

namespace Modules\Printer\Services\Agent;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\Printer;

interface PrintAgentServiceInterface
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
     * @return Printer
     */
    public function getModel(): PrintAgent;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return PrintAgent|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): PrintAgent|Builder|EloquentCollection|array;

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
     * @return PrintAgent
     * @throws ModelNotFoundException
     */
    public function show(int $id): PrintAgent;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return PrintAgent
     */
    public function store(array $data): PrintAgent;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return PrintAgent
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): PrintAgent;

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
     * @param int|null $branchId
     * @return array
     */
    public function getFormMeta(?int $branchId = null): array;
}
