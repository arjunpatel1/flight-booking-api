<?php

namespace Modules\Core\Automation;

class AutomationDecision
{
    public function __construct(
        public readonly AutomationRule $rule,
        public readonly array $actionPolicy,
        public readonly string $state,
        public readonly bool $executed = false,
        public readonly ?string $executionId = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            ...$this->rule->toArray(),
            'state' => $this->state,
            'executable' => ($this->actionPolicy['allowed'] ?? false) && $this->state !== 'inactive',
            'executed' => $this->executed,
            'execution_id' => $this->executionId,
            'action_policy' => $this->actionPolicy,
            'audit' => [
                'log_name' => 'restaurant_automation',
                'event' => $this->executed ? 'automation_executed' : 'automation_evaluated',
                'rule_id' => $this->rule->id,
                'domain' => $this->rule->domain,
            ],
        ];
    }
}
