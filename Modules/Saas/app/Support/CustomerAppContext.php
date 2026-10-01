<?php

namespace Modules\Saas\Support;

use Modules\Branch\Models\Branch;
use Modules\Saas\Models\CustomerAppRegistration;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;

final readonly class CustomerAppContext
{
    public function __construct(
        public CustomerAppRegistration $appRegistration,
        public Tenant $tenant,
        public ?User $customer,
        public ?Branch $branch,
        public string $installationId,
    ) {
    }

    public function tenantId(): int
    {
        return (int) $this->tenant->getKey();
    }

    public function customerId(): ?int
    {
        return $this->customer ? (int) $this->customer->getKey() : null;
    }
}
