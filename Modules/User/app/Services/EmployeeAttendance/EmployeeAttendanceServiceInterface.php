<?php

namespace Modules\User\Services\EmployeeAttendance;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\User\Models\EmployeeAttendance;

interface EmployeeAttendanceServiceInterface
{
    public function label(): string;

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    public function clockIn(array $data): EmployeeAttendance;

    public function clockOut(int $id, array $data): EmployeeAttendance;

    public function update(int $id, array $data): EmployeeAttendance;

    public function summary(array $filters = []): array;

    public function getStructureFilters(): array;
}
