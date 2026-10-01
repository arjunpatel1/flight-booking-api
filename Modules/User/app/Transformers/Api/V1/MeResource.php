<?php

namespace Modules\User\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;

/** @mixin User */
class MeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $roles = $this->getRolesWithPermissions();

        return [
            "id" => $this->id,
            "name" => $this->name,
            "profile_photo_url" => $this->profile_photo_url,
            "username" => $this->username,
            "email" => $this->email,
            "mfa_enabled" => (bool) $this->mfa_enabled,
            "gender" => $this->gender?->toTrans(),
            "branch" => is_null($this->branch_id)
                ? null
                : [
                    "id" => $this->branch_id,
                    "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
                ],
            'role' => $roles[0] ?? null,
            'roles' => $roles,
            'effective_permissions' => $this->getEffectivePermissions(),
            'plan_features' => $this->planFeatures(),
            'subscription_plan' => $this->subscriptionPlan(),
            'access_scope' => $this->accessScope(),
            'tenant_id' => $this->tenant_id,
            'branch_id' => $this->branch_id,
        ];
    }

    private function planFeatures(): array
    {
        if (! $this->tenant_id || ! class_exists(Tenant::class)) {
            return [];
        }

        $tenant = Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->with('activeSubscription.plan:id,features')
            ->find($this->tenant_id);

        return $tenant ? app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class)->features($tenant) : [];
    }

    private function subscriptionPlan(): ?array
    {
        if (! $this->tenant_id || ! class_exists(Tenant::class)) {
            return null;
        }

        $plan = Tenant::query()
            ->withoutGlobalScopes()
            ->withoutGlobalActive()
            ->with('activeSubscription.plan:id,name,code,access_scope,billing_cycle,features,limits')
            ->find($this->tenant_id)
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
