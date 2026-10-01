<?php

namespace Modules\Saas\Events;

use Modules\Saas\Models\SaasProvisioningRun;

final readonly class ProvisioningStepFailed
{
    public function __construct(public SaasProvisioningRun $run, public string $step, public string $reason)
    {
    }
}
