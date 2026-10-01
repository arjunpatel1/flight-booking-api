<?php

namespace Modules\Saas\Services\SubscriptionPlan;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Saas\Models\SubscriptionPlan;

interface SubscriptionPlanServiceInterface
{
    public function label(): string;

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    public function show(int $id): SubscriptionPlan;

    public function store(array $data): SubscriptionPlan;

    public function update(int $id, array $data): SubscriptionPlan;

    public function destroy(int|array|string $ids): bool;

    public function getStructureFilters(): array;

    public function getFormMeta(): array;
}
