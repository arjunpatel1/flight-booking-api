<?php

namespace Modules\Saas\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSubscriptionPlanRequest extends FormRequest
{
    public function rules(): array
    {
        $planId = $this->route('id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:120', Rule::unique('subscription_plans', 'code')->ignore($planId)],
            'description' => ['nullable', 'string', 'max:1000'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'quarterly', 'yearly', 'lifetime'])],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'access_scope' => ['required', Rule::in(['branch', 'tenant'])],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:120'],
            'limits' => ['nullable', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
