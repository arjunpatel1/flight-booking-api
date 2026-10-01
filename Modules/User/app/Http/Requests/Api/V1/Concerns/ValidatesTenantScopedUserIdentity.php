<?php

namespace Modules\User\Http\Requests\Api\V1\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ValidatesTenantScopedUserIdentity
{
    protected function uniqueActiveUserValue(string $column): Unique
    {
        $tenantId = $this->tenantIdForIdentityValidation();

        $rule = Rule::unique('users', $column)
            ->ignore($this->route('id'))
            ->whereNull('deleted_at');

        return $tenantId === null
            ? $rule->whereNull('tenant_id')
            : $rule->where('tenant_id', $tenantId);
    }

    protected function tenantIdForIdentityValidation(): ?int
    {
        $actor = auth()->user();

        if ($actor?->assignedToTenant() && ! $actor->isSuperAdmin()) {
            return $actor->tenantId();
        }

        $tenantId = $this->input('tenant_id');
        if (is_numeric($tenantId)) {
            return (int) $tenantId;
        }

        $branchId = $this->input('branch_id');
        if (is_numeric($branchId)) {
            $branchTenantId = DB::table('branches')
                ->where('id', (int) $branchId)
                ->value('tenant_id');

            if (is_numeric($branchTenantId)) {
                return (int) $branchTenantId;
            }
        }

        $userId = $this->route('id');
        if (is_numeric($userId)) {
            $userTenantId = DB::table('users')
                ->where('id', (int) $userId)
                ->value('tenant_id');

            if (is_numeric($userTenantId)) {
                return (int) $userTenantId;
            }
        }

        return null;
    }
}
