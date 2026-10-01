<?php

namespace Modules\Saas\Services\Tenant;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Saas\Models\Tenant;

interface TenantServiceInterface
{
    public function label(): string;

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    public function registrySummary(): array;

    public function show(int $id): Tenant;

    public function store(array $data): Tenant;

    public function update(int $id, array $data): Tenant;

    public function destroy(int|array|string $ids): bool;

    public function getStructureFilters(): array;
}
