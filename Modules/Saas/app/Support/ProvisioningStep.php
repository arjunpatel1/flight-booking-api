<?php

namespace Modules\Saas\Support;

final readonly class ProvisioningStep
{
    public function __construct(
        public string $key,
        public string $state,
        public string $queue,
        public int $weight = 1,
        public int $estimatedSeconds = 5,
        public bool $rerunnable = true,
    ) {
    }
}
