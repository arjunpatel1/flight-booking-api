<?php

namespace Modules\User\Services\EmployeeCompensation;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\User\Models\EmployeeCompensation;

interface EmployeeCompensationServiceInterface
{
    public function label(): string;

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    public function show(int $id): EmployeeCompensation;

    public function store(array $data): EmployeeCompensation;

    public function update(int $id, array $data): EmployeeCompensation;

    public function destroy(int|array|string $ids): bool;

    public function getStructureFilters(): array;

    public function getFormMeta(?int $branchId = null): array;
}
