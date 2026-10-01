<?php

namespace Modules\ActivityLog\Services\ActivityLog;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\ActivityLog\Models\ActivityLog;

interface ActivityLogServiceInterface
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
     * Get structure filters for frontend
     *
     * @return array
     */
    public function getStructureFilters(): array;

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return ActivityLog
     * @throws ModelNotFoundException
     */
    public function show(int $id): ActivityLog;

    /** Delete only records visible to the current tenant and older than the retention window. */
    public function prune(int $retentionDays): int;
}
