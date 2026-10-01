<?php

namespace Modules\Saas\Services\CustomerApp;

use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppRegistration;

class CustomerAppResourceAuthorizationService
{
    /**
     * Enforce ownership after resolving a resource without trusting a client tenant header.
     * Customer endpoints must derive $resourceTenantId from the persisted resource.
     */
    public function assertOwned(
        CustomerAppRegistration $registration,
        ?int $resourceTenantId,
        string $resourceType,
    ): void {
        if ($resourceTenantId === null || $resourceTenantId !== (int) $registration->tenant_id) {
            throw new CustomerAppAuthorizationException(
                'RESOURCE_FORBIDDEN',
                ucfirst($resourceType).' does not belong to this restaurant.',
            );
        }
    }
}
