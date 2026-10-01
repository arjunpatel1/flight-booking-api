<?php

namespace Modules\Saas\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Saas\Models\TenantSubscription;

/** @mixin TenantSubscription */
class TenantSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant' => [
                'id' => $this->tenant_id,
                'name' => $this->relationLoaded('tenant') ? $this->tenant?->name : '',
                'slug' => $this->relationLoaded('tenant') ? $this->tenant?->slug : '',
            ],
            'plan' => [
                'id' => $this->subscription_plan_id,
                'name' => $this->relationLoaded('plan') ? $this->plan?->name : '',
                'code' => $this->relationLoaded('plan') ? $this->plan?->code : '',
            ],
            'status' => [
                'id' => $this->status,
                'name' => __("saas::tenant_subscriptions.statuses.{$this->status}"),
                'access_active' => in_array($this->status, ['active', 'trial'], true),
            ],
            'starts_at' => dateTimeFormat($this->starts_at),
            'ends_at' => $this->ends_at ? dateTimeFormat($this->ends_at) : null,
            'trial_ends_at' => $this->trial_ends_at ? dateTimeFormat($this->trial_ends_at) : null,
            'effective_ends_at' => $this->ends_at
                ? dateTimeFormat($this->ends_at)
                : ($this->trial_ends_at ? dateTimeFormat($this->trial_ends_at) : null),
            'cancelled_at' => $this->cancelled_at ? dateTimeFormat($this->cancelled_at) : null,
            'starts_at_input' => $this->starts_at?->format('Y-m-d\TH:i'),
            'ends_at_input' => $this->ends_at?->format('Y-m-d\TH:i'),
            'trial_ends_at_input' => $this->trial_ends_at?->format('Y-m-d\TH:i'),
            'cancelled_at_input' => $this->cancelled_at?->format('Y-m-d\TH:i'),
            'overrides' => collect($this->overrides ?: [])->except('_features')->all(),
            'feature_access' => (array) data_get($this->overrides ?: [], '_features', []),
            'created_at' => dateTimeFormat($this->created_at),
            'updated_at' => dateTimeFormat($this->updated_at),
        ];
    }
}
