<?php

namespace Modules\Tracking\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface LiveTrackingServiceInterface
{
    public function activeOrders(array $filters = []): LengthAwarePaginator;

    public function show(int|string $id): array;

    public function getStructureFilters(): array;

    public function pendingCount(): int;

    public function accept(int|string $id): array;

    public function reject(int|string $id, array $data): array;

    public function rejectMeta(): array;
}
