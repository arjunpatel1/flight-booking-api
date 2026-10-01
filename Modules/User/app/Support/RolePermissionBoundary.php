<?php

namespace Modules\User\Support;

use Illuminate\Validation\ValidationException;
use Modules\User\Enums\DefaultRole;
use Modules\User\Facades\Permission;
use Modules\User\Models\User;

/**
 * Prevent privilege delegation beyond the actor's own authority.
 */
class RolePermissionBoundary
{
    /** @return array<int, string> */
    public function allowedNames(?User $actor = null): array
    {
        $actor ??= auth()->user();
        if (! $actor) {
            return [];
        }

        $all = Permission::getPermissionNames();
        $isPlatformSecurityAdministrator = $actor->isSuperAdmin()
            || (! $actor->assignedToTenant()
                && ! $actor->assignedToBranch()
                && $actor->can('admin.saas.manage'));

        if ($isPlatformSecurityAdministrator) {
            return array_values($all);
        }

        $allowed = array_values(array_intersect($all, $actor->getEffectivePermissions()));

        if ($actor->assignedToTenant() || $actor->assignedToBranch()) {
            $allowed = array_values(array_filter(
                $allowed,
                fn (string $permission) => ! str($permission)->startsWith(DefaultRole::platformOnlyPermissionPrefixes())
            ));
        }

        return $allowed;
    }

    public function assertAllowed(array $permissions, ?User $actor = null): void
    {
        $requested = array_values(array_unique(array_filter($permissions, 'is_string')));
        if (array_diff($requested, $this->allowedNames($actor)) !== []) {
            throw ValidationException::withMessages([
                'permissions' => ['One or more permissions exceed your access scope.'],
            ]);
        }
    }

    public function filterTree(array $tree, ?User $actor = null): array
    {
        $allowed = array_flip($this->allowedNames($actor));

        return collect($tree)
            ->map(function (array $group) use ($allowed): array {
                $group['actions'] = array_values(array_filter(
                    $group['actions'] ?? [],
                    fn (array $action) => isset($allowed[$action['id'] ?? ''])
                ));

                return $group;
            })
            ->filter(fn (array $group) => $group['actions'] !== [])
            ->values()
            ->all();
    }
}
