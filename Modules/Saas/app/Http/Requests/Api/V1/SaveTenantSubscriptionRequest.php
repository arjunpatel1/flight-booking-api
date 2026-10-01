<?php

namespace Modules\Saas\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTenantSubscriptionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'exists:tenants,id'],
            'subscription_plan_id' => ['nullable', 'exists:subscription_plans,id'],
            'status' => ['required', Rule::in(['trial', 'active', 'past_due', 'cancelled', 'expired'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'trial_ends_at' => ['nullable', 'date'],
            'cancelled_at' => ['nullable', 'date'],
            'overrides' => ['nullable', 'array'],
            'overrides.*' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'feature_access' => ['nullable', 'array'],
            'feature_access.*' => ['nullable', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
