<?php

namespace Modules\Pos\Services\PosSession;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Pos\Models\PosSession;
use Throwable;

interface PosSessionServiceInterface
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
     * @return PosSession
     */
    public function getModel(): PosSession;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return PosSession|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): PosSession|Builder|EloquentCollection|array;

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
     * @return PosSession
     * @throws ModelNotFoundException
     */
    public function show(int $id): PosSession;

    /**
     * Open pos sessions.
     *
     * @param array $data
     * @return PosSession
     * @throws Throwable
     */
    public function open(array $data): PosSession;

    /**
     * Close pos sessions
     *
     * @param int $id
     * @param array $data
     * @return PosSession
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function close(int $id, array $data): PosSession;

    /**
     * Get structure filters for frontend
     *
     * @param int|null $branchId
     * @return array
     */
    public function getStructureFilters(?int $branchId = null): array;

    /**
     * Get form meta
     *
     * @param int|null $branchId
     * @return array
     */
    public function getFormMeta(?int $branchId = null): array;
}
