<?php

namespace Modules\User\Http\Requests\Api\V1;

use Illuminate\Validation\Rules\Password;
use Modules\Core\Http\Requests\Request;
use Modules\User\Http\Requests\Api\V1\Concerns\ValidatesTenantScopedUserIdentity;

class QuickStoreCustomerRequest extends Request
{
    use ValidatesTenantScopedUserIdentity;

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => ['nullable', 'string', 'max:50', $this->uniqueActiveUserValue('phone')],
            'phone_country_iso_code' => 'nullable|string|max:3',
            'email' => ['nullable', 'email:rfc', 'max:50', $this->uniqueActiveUserValue('email')],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'anniversary_date' => ['nullable', 'date', 'before_or_equal:today'],
            'whatsapp_marketing_consent' => ['sometimes', 'boolean'],
            'whatsapp_consent_source' => ['nullable', 'string', 'max:80'],
            'branch_id' => 'nullable|integer|exists:branches,id',
            'username' => [
                'nullable', 'string', 'max:255',
                $this->uniqueActiveUserValue('username'),
            ],
            'password' => [
                'nullable',
                'string',
                Password::min(8)
                    ->max(20)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return 'user::attributes.users';
    }
}
