<?php

namespace Modules\Saas\Services\TenantSubscription;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Saas\Models\TenantSubscription;

interface TenantSubscriptionServiceInterface
{
    public function label(): string;

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    public function show(int $id): TenantSubscription;

    public function store(array $data): TenantSubscription;

    public function update(int $id, array $data): TenantSubscription;

    public function destroy(int|array|string $ids): bool;

    public function getStructureFilters(): array;

    public function getFormMeta(): array;
}
