<?php

namespace Modules\Saas\Support;

final readonly class ProvisioningEvent
{
    public function __construct(
        public string $type,
        public string $runUuid,
        public ?string $step = null,
        public array $payload = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'run_uuid' => $this->runUuid,
            'step' => $this->step,
            'payload' => $this->payload,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
