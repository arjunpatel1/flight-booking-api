<?php

namespace Modules\User\Traits;

use Modules\User\Enums\DefaultRole;

trait HasRoles
{
    use \Spatie\Permission\Traits\HasRoles;

    /**
     * Determine whether this is a platform super-administrator identity.
     *
     * The role name alone must never bypass tenant scopes or Gate checks. A
     * contaminated tenant account carrying the role remains a tenant account.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(DefaultRole::SuperAdmin->value)
            && $this->tenantId() === null
            && $this->branchId() === null;
    }

    /**
     * Get roles with permissions
     *
     * @return array
     */
    public function getRolesWithPermissions(): array
    {
        return $this->roles()->with('permissions')
            ->get()
            ->map(fn($role) => [
                "name" => $role->name,
                "display_name" => $role->display_name,
                "permissions" => $role->permissions->pluck('name')->toArray(),
            ])
            ->toArray();
    }

    public function getEffectivePermissions(): array
    {
        if ($this->isSuperAdmin()) {
            return ['*'];
        }

        // Spatie's getAllPermissions() merges permissions inherited through
        // roles with permissions assigned directly to a user. Direct grants
        // are required for tenant-specific feature access; omitting them here
        // made the API authorize a route while the SPA redirected to Dashboard.
        return $this->getAllPermissions()
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function accessScope(): string
    {
        if ($this->isSuperAdmin()) {
            return 'platform';
        }

        if ($this->hasRole(DefaultRole::EnterpriseAdmin->value) && $this->tenantId() !== null) {
            return 'tenant';
        }

        return $this->branchId() !== null ? 'branch' : 'unassigned';
    }
}
