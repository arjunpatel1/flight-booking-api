<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Modules\User\Facades\Permission;
use Modules\User\Models\Role;
use Modules\User\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission editing from the SaaS tenant workspace (Access → Roles & permissions):
 *  - custom roles owned by one agency, with hand-picked permissions;
 *  - the built-in agency roles, edited for every agency at once.
 *
 * Only flight-agency permissions can be granted (DefaultRole::isFlightAgencyPermission);
 * platform control-plane and restaurant permissions are rejected.
 */
class SaasTenantRoleController extends Controller
{
    /** Built-in roles a platform admin may re-tune. Platform and customer roles are fixed. */
    private const EDITABLE_BUILT_IN_ROLES = [
        DefaultRole::EnterpriseAdmin,
        DefaultRole::AdminBranch,
        DefaultRole::Manager,
        DefaultRole::FlightAdmin,
        DefaultRole::FlightDistributor,
        DefaultRole::FlightAgent,
    ];

    public static function editableBuiltInRoleNames(): array
    {
        return array_map(fn (DefaultRole $role) => $role->value, self::EDITABLE_BUILT_IN_ROLES);
    }

    /**
     * Custom roles belong to an agency through their creator: Role::scopeVisibleTo
     * shows a custom role inside the tenant of the user in created_by.
     */
    public static function tenantCustomRoles(Tenant $tenant): Builder
    {
        return Role::query()
            ->where('built_in', false)
            ->whereHas('createdBy', fn (Builder $creator) => $creator
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id));
    }

    public function storeCustomRole(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $this->validateCustomRole($request);

        $owner = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->first();
        if (! $owner) {
            throw ValidationException::withMessages([
                'display_name' => 'Add a team member to this agency before creating its own roles.',
            ]);
        }

        $role = DB::transaction(function () use ($data, $owner) {
            $role = Role::create([
                'display_name' => $this->translations($data['display_name']),
                'name' => Role::getSlugName($data['display_name']),
                'built_in' => false,
                // Owned by the agency, not by the platform admin creating it.
                'created_by' => $owner->id,
            ]);
            $role->syncPermissions($data['permissions']);

            return $role;
        });

        $this->forgetRoleCaches();

        return ApiResponse::created($this->rolePayload($role), message: 'Agency role created.');
    }

    public function updateCustomRole(Request $request, Tenant $tenant, int $role): JsonResponse
    {
        $customRole = self::tenantCustomRoles($tenant)->findOrFail($role);
        $data = $this->validateCustomRole($request);

        DB::transaction(function () use ($customRole, $data) {
            $customRole->update(['display_name' => $this->translations($data['display_name'])]);
            $customRole->syncPermissions($data['permissions']);
        });

        $this->forgetRoleCaches();

        return ApiResponse::success($this->rolePayload($customRole->fresh()), 'Agency role updated.');
    }

    public function destroyCustomRole(Tenant $tenant, int $role): JsonResponse
    {
        $customRole = self::tenantCustomRoles($tenant)->findOrFail($role);

        // The pivot key is configured as null when it is the default "role_id".
        $assigned = DB::table(config('permission.table_names.model_has_roles') ?: 'model_has_roles')
            ->where(config('permission.column_names.role_pivot_key') ?: 'role_id', $customRole->id)
            ->count();
        abort_if($assigned > 0, 422, "This role is assigned to {$assigned} team member(s). Give them another role first.");

        $customRole->delete();
        $this->forgetRoleCaches();

        return ApiResponse::success(['id' => $role], 'Agency role deleted.');
    }

    /** Replace a built-in role's permissions for every agency. */
    public function updateBuiltInRole(Request $request, string $role): JsonResponse
    {
        abort_unless(in_array($role, self::editableBuiltInRoleNames(), true), 422, 'This role cannot be edited.');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in($this->grantablePermissions())],
        ]);

        $builtIn = Role::query()->where('name', $role)->where('built_in', true)->firstOrFail();

        DB::transaction(function () use ($builtIn, $data) {
            $builtIn->forceFill(['permissions_customized' => true])->save();
            $builtIn->syncPermissions($data['permissions']);
        });

        $this->forgetRoleCaches();

        return ApiResponse::success($this->rolePayload($builtIn->fresh()), 'Role permissions updated for every agency.');
    }

    /** Put a built-in role back on its default permissions and let sync manage it again. */
    public function resetBuiltInRole(string $role): JsonResponse
    {
        abort_unless(in_array($role, self::editableBuiltInRoleNames(), true), 422, 'This role cannot be edited.');

        $builtIn = Role::query()->where('name', $role)->where('built_in', true)->firstOrFail();

        DB::transaction(function () use ($builtIn, $role) {
            $builtIn->forceFill(['permissions_customized' => false])->save();
            $builtIn->syncPermissions(DefaultRole::from($role)->effectivePermissions(Permission::getPermissionNames()));
        });

        $this->forgetRoleCaches();

        return ApiResponse::success($this->rolePayload($builtIn->fresh()), 'Role reset to its default permissions.');
    }

    private function validateCustomRole(Request $request): array
    {
        return $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in($this->grantablePermissions())],
        ]);
    }

    /** Registered permissions an agency role may hold. */
    private function grantablePermissions(): array
    {
        return array_values(array_filter(
            Permission::getPermissionNames(),
            fn (string $permission) => DefaultRole::isFlightAgencyPermission($permission)
                && ! str($permission)->startsWith(DefaultRole::platformOnlyPermissionPrefixes()),
        ));
    }

    /** The same name in every supported language (display_name is translatable). */
    private function translations(string $name): array
    {
        $locales = (array) (setting('supported_locales') ?: [config('app.locale')]);

        return collect($locales)->mapWithKeys(fn ($locale) => [$locale => trim($name)])->all();
    }

    private function rolePayload(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'built_in' => (bool) $role->built_in,
            'customized' => (bool) $role->permissions_customized,
            'permissions' => $role->permissions()->orderBy('name')->pluck('name')->values(),
        ];
    }

    private function forgetRoleCaches(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::tags('roles')->flush();
    }
}
