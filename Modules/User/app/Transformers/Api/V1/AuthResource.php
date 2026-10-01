<?php

namespace Modules\User\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;

/** @mixin User */
class AuthResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $roles = $this->getRolesWithPermissions();
        $role = $roles[0] ?? null;

        return [
            "id" => $this->id,
            "name" => $this->name,
            "profile_photo_url" => $this->profile_photo_url,
            "username" => $this->username,
            "email" => $this->email,
            "mfa_enabled" => (bool) $this->mfa_enabled,
            "branch_id" => $this->branch_id,
            "tenant_id" => $this->attribute('tenant_id'),
            'role' => $role,
            'roles' => $roles,
            'effective_permissions' => $this->getEffectivePermissions(),
            'plan_features' => $this->planFeatures(),
            'subscription_plan' => $this->subscriptionPlan(),
            'access_scope' => $this->accessScope(),
            "assigned_to_branch" => $this->assignedToBranch(),
            "assigned_to_tenant" => $this->assignedToTenant(),
        ];
    }

    private function attribute(string $key): mixed
    {
        return array_key_exists($key, $this->resource->getAttributes())
            ? $this->resource->getAttribute($key)
            : null;
    }

    private function planFeatures(): array
    {
        $tenantId = $this->attribute('tenant_id');
        if (! $tenantId || ! class_exists(Tenant::class)) {
            return [];
        }

        $tenant = Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->with('activeSubscription.plan:id,features')
            ->find($tenantId);

        return $tenant ? app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class)->features($tenant) : [];
    }

    private function subscriptionPlan(): ?array
    {
        $tenantId = $this->attribute('tenant_id');
        if (! $tenantId || ! class_exists(Tenant::class)) {
            return null;
        }

        $plan = Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->with('activeSubscription.plan:id,name,code,access_scope,billing_cycle,features,limits')
            ->find($tenantId)
            ?->activeSubscription
            ?->plan;

        return $plan ? [
            'id' => $plan->id,
            'name' => $plan->name,
            'code' => $plan->code,
            'access_scope' => $plan->access_scope ?: 'tenant',
            'billing_cycle' => $plan->billing_cycle,
            'features' => $plan->features ?: [],
            'limits' => $plan->limits ?: [],
        ] : null;
    }
}
