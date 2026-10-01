<?php

namespace Modules\User\Services\EmployeePayroll;

interface EmployeePayrollServiceInterface
{
    public function preview(array $filters = []): array;

    public function runs(array $filters = []): array;

    public function show(int $id): array;

    public function generate(array $data): array;

    public function approve(int $id): array;

    public function markPaid(int $id, array $data): array;

    public function void(int $id, string $reason): array;

    public function statutoryExport(int $id): array;
}
