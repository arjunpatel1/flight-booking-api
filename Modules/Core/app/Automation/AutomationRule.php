<?php

namespace Modules\Core\Automation;

class AutomationRule
{
    public function __construct(
        public readonly string $id,
        public readonly string $domain,
        public readonly string $trigger,
        public readonly string $title,
        public readonly string $reason,
        public readonly string $impact,
        public readonly string $recommendedAction,
        public readonly array $conditions,
        public readonly array $safetyRules,
        public readonly array $permissions,
        public readonly array $action,
        public readonly array $rollback,
        public readonly array $notification,
        public readonly array $evidence = [],
        public readonly string $severity = 'info',
        public readonly string $mode = 'suggest',
        public readonly bool $autoEnabled = false,
    ) {
    }

    public function conditionsPassed(): bool
    {
        return collect($this->conditions)->every(fn(array $condition) => (bool) ($condition['passed'] ?? false));
    }

    public function safetyPassed(): bool
    {
        return collect($this->safetyRules)->every(fn(array $rule) => (bool) ($rule['passed'] ?? false));
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'trigger' => $this->trigger,
            'title' => $this->title,
            'reason' => $this->reason,
            'impact' => $this->impact,
            'recommended_action' => $this->recommendedAction,
            'conditions' => $this->conditions,
            'safety_rules' => $this->safetyRules,
            'permissions' => $this->permissions,
            'action' => $this->action,
            'rollback' => $this->rollback,
            'notification' => $this->notification,
            'evidence' => $this->evidence,
            'severity' => $this->severity,
            'mode' => $this->mode,
            'auto_enabled' => $this->autoEnabled,
        ];
    }
}
