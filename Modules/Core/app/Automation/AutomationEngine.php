<?php

namespace Modules\Core\Automation;

use Modules\Support\ActionPolicy;
use Modules\User\Models\User;

class AutomationEngine
{
    /**
     * @param AutomationRule[] $rules
     */
    public function evaluate(array $rules, User $user): array
    {
        $decisions = collect($rules)
            ->map(fn(AutomationRule $rule) => $this->decide($rule, $user))
            ->map(fn(AutomationDecision $decision) => $decision->toArray())
            ->values();

        return [
            'framework' => [
                'version' => 1,
                'mode' => 'deterministic',
                'policy_source' => ActionPolicy::class,
                'execution_policy' => 'suggest_first_auto_only_when_rule_is_enabled_and_safe',
                'audit_stream' => 'restaurant_automation',
            ],
            'summary' => [
                'total' => $decisions->count(),
                'active' => $decisions->where('state', '!=', 'inactive')->count(),
                'auto_eligible' => $decisions->where('state', 'auto_eligible')->count(),
                'blocked' => $decisions->where('state', 'blocked')->count(),
                'critical' => $decisions->where('severity', 'critical')->count(),
            ],
            'decisions' => $decisions->all(),
        ];
    }

    private function decide(AutomationRule $rule, User $user): AutomationDecision
    {
        $conditionsPassed = $rule->conditionsPassed();
        $safetyPassed = $rule->safetyPassed();
        $permissionAllowed = $this->canAll($user, $rule->permissions);
        $allowed = $conditionsPassed && $safetyPassed && $permissionAllowed;
        $state = match (true) {
            ! $conditionsPassed => 'inactive',
            ! $safetyPassed || ! $permissionAllowed => 'blocked',
            $rule->autoEnabled && $rule->mode === 'auto' => 'auto_eligible',
            default => 'suggested',
        };
        $reason = match (true) {
            ! $conditionsPassed => 'Trigger conditions are not met.',
            ! $safetyPassed => 'Safety rules blocked automation.',
            ! $permissionAllowed => 'Permission denied.',
            default => null,
        };

        return new AutomationDecision(
            rule: $rule,
            actionPolicy: ActionPolicy::make(
                allowed: $allowed,
                reason: $reason,
                visible: $state !== 'inactive',
                loadingKey: $rule->action['loading_key'] ?? $rule->id,
                permissions: $rule->permissions,
                confirmationRequired: ($rule->action['confirmation_required'] ?? true),
                meta: [
                    'automation_state' => $state,
                    'auto_execution_enabled' => $rule->autoEnabled,
                ],
            ),
            state: $state,
        );
    }

    private function canAll(User $user, array $permissions): bool
    {
        return collect($permissions)
            ->filter()
            ->every(fn(string $permission) => $user->can($permission));
    }
}
