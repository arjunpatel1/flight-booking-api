<?php

namespace Modules\Saas\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Support\Money;

/** @mixin SubscriptionPlan */
class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'billing_cycle' => [
                'id' => $this->billing_cycle,
                'name' => __("saas::subscription_plans.billing_cycles.{$this->billing_cycle}"),
            ],
            'price' => new Money((float) $this->price, $this->currency),
            'price_amount' => (float) $this->price,
            'currency' => $this->currency,
            'access_scope' => $this->access_scope ?: 'tenant',
            'features' => $this->features ?: [],
            'limits' => $this->limits ?: [],
            'subscriptions_count' => $this->subscriptions_count ?? null,
            'is_active' => $this->is_active,
            'created_at' => dateTimeFormat($this->created_at),
            'updated_at' => dateTimeFormat($this->updated_at),
        ];
    }
}
