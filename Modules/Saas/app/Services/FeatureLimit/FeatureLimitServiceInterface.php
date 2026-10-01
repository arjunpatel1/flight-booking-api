<?php

namespace Modules\Saas\Services\FeatureLimit;

use Modules\Saas\Models\Tenant;

interface FeatureLimitServiceInterface
{
    public function resolve(Tenant|int $tenant, string $feature): array;

    public function isEnabled(Tenant|int $tenant, string $feature): bool;

    public function hasCapacity(Tenant|int $tenant, string $feature, int $increment = 1): bool;

    public function recordUsage(Tenant|int $tenant, string $feature, int $increment = 1): array;

    public function releaseUsage(Tenant|int $tenant, string $feature, int $decrement = 1): array;
}
