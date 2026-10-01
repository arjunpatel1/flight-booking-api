<?php

namespace Modules\Aggregator\Support;

use Modules\Aggregator\Models\PartnerApiCredential;
use Modules\Aggregator\Models\PartnerApiIntegration;

final readonly class PartnerContext
{
    public function __construct(
        public PartnerApiCredential $credential,
        public PartnerApiIntegration $partner,
    ) {}

    public function tenantId(): int
    {
        return (int) $this->credential->tenant_id;
    }

    public function canAccessBranch(int $branchId): bool
    {
        $allowed = $this->credential->branch_ids;

        return empty($allowed) || in_array($branchId, array_map('intval', $allowed), true);
    }

    public function hasScope(string $scope): bool
    {
        $granted = $this->credential->scopes ?? [];
        if (in_array('*', $granted, true) || in_array($scope, $granted, true)) {
            return true;
        }

        // A credential allowed to mutate orders must be able to read back only
        // the partner/tenant-scoped orders it created. This also repairs older
        // credentials issued with orders:write before the UI linked the scopes.
        return $scope === 'orders:read' && in_array('orders:write', $granted, true);
    }
}
