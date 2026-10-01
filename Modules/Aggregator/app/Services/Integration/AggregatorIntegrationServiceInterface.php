<?php

namespace Modules\Aggregator\Services\Integration;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Aggregator\Models\AggregatorIntegration;

interface AggregatorIntegrationServiceInterface
{
    public function label(): string;

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    public function show(int $id): AggregatorIntegration;

    public function store(array $data): AggregatorIntegration;

    public function update(int $id, array $data): AggregatorIntegration;

    public function toggle(int $id, bool $active): AggregatorIntegration;

    public function destroy(int|array|string $ids): bool;

    public function getFormMeta(): array;

    public function dispatchSync(int $id, string $type): void;

    public function providerStatus(int $id): array;

    public function testConnection(int $id): array;

    public function retryLog(int $logId): void;
}
