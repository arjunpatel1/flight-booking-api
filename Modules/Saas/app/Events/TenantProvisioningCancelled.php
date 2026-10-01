<?php

namespace Modules\Saas\Events;

use Modules\Saas\Models\SaasProvisioningRun;

final readonly class TenantProvisioningCancelled
{
    public function __construct(public SaasProvisioningRun $run)
    {
    }
}
