<?php

namespace Modules\ActivityLog\Services\AuthenticationLog;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\ActivityLog\Models\AuthenticationLog;

interface AuthenticationLogServiceInterface
{
    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array|null $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters, ?array $sorts = []): LengthAwarePaginator;

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return AuthenticationLog
     * @throws ModelNotFoundException
     */
    public function show(int $id): AuthenticationLog;
}
