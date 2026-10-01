<?php

namespace Modules\Saas\Events;

use Modules\Saas\Models\SaasProvisioningRun;

final readonly class ProvisioningStepStarted
{
    public function __construct(public SaasProvisioningRun $run, public string $step)
    {
    }
}
