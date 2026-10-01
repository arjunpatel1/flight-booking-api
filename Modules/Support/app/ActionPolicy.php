<?php

namespace Modules\Support;

final class ActionPolicy
{
    /**
     * Standard backend contract for UI actions across POS, kitchen, payments,
     * printer, reservations, sessions, and future platform modules.
     */
    public static function make(
        bool $allowed,
        ?string $reason = null,
        bool $visible = true,
        bool $managerApprovalRequired = false,
        ?string $loadingKey = null,
        array|string $permissions = [],
        bool $confirmationRequired = false,
        array $meta = [],
    ): array {
        $permissions = is_array($permissions) ? array_values($permissions) : [$permissions];
        $permissions = array_values(array_filter(
            array_map(fn($permission) => is_scalar($permission) ? (string) $permission : null, $permissions)
        ));

        $disabledReason = $allowed ? null : $reason;

        return [
            'allowed' => $allowed,
            'visible' => $visible,
            'reason' => $disabledReason,
            'disabled_reason' => $disabledReason,
            'loading_key' => $loadingKey,
            'requires_manager_approval' => $allowed && $managerApprovalRequired,
            'manager_approval_required' => $allowed && $managerApprovalRequired,
            'permissions' => $permissions,
            'confirmation_required' => $allowed && $confirmationRequired,
            ...$meta,
        ];
    }
}
