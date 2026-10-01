<?php

namespace Modules\User\Services\EmployeeShift;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\User\Models\EmployeeShift;

interface EmployeeShiftServiceInterface
{
    public function label(): string;

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    public function show(int $id): EmployeeShift;

    public function store(array $data): EmployeeShift;

    public function update(int $id, array $data): EmployeeShift;

    public function destroy(int|array|string $ids): bool;

    public function getStructureFilters(): array;

    public function getFormMeta(?int $branchId = null): array;
}
