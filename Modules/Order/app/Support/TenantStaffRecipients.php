<?php

namespace Modules\Order\Support;

use Illuminate\Support\Collection;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

/**
 * Resolves the active staff of one tenant who may act on an operational
 * alert (new order, delivery problem, ...).
 *
 * Access is evaluated explicitly on the staff guard. `$user->can()` resolves
 * permissions through the guard of the *current request*, so when a customer
 * (Sanctum customer guard) places an order every staff permission lookup
 * silently fails and nobody is notified.
 */
final class TenantStaffRecipients
{
    public const STAFF_GUARD = 'api';

    private const MANAGER_ROLES = [
        DefaultRole::Admin->value,
        DefaultRole::EnterpriseAdmin->value,
        DefaultRole::AdminBranch->value,
    ];

    /**
     * @param  list<string>  $permissions  any one of these grants access
     * @return Collection<int, User>
     */
    public static function withAnyPermission(int $tenantId, array $permissions): Collection
    {
        return User::query()->withoutGlobalScopes()
            ->with(['roles.permissions', 'permissions'])
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('can_login', true)
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn (User $user) => self::mayReceive($user, $permissions))
            ->values();
    }

    private static function mayReceive(User $user, array $permissions): bool
    {
        if ($user->hasRole(self::MANAGER_ROLES, self::STAFF_GUARD)) {
            return true;
        }

        foreach ($permissions as $permission) {
            // checkPermissionTo() returns false for permissions that do not
            // exist on the guard instead of throwing.
            if ($user->checkPermissionTo($permission, self::STAFF_GUARD)) {
                return true;
            }
        }

        return false;
    }
}
