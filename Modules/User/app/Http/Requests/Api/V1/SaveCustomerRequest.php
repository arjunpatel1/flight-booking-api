<?php

namespace Modules\User\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Modules\Core\Http\Requests\Request;
use Modules\User\Enums\GenderType;
use Modules\User\Http\Requests\Api\V1\Concerns\ValidatesTenantScopedUserIdentity;

/**
 * @property int|null $role
 */
class SaveCustomerRequest extends Request
{
    use ValidatesTenantScopedUserIdentity;

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'username' => [
                'bail', 'nullable', 'string', 'max:255',
                $this->uniqueActiveUserValue('username'),
            ],
            'email' => ['bail', 'nullable', 'email:rfc', 'max:50', $this->uniqueActiveUserValue('email')],
            'password' => [
                'nullable',
                'string',
                Password::min(8)
                    ->max(20)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
                'confirmed',
            ],
            'gender' => ['nullable', Rule::enum(GenderType::class)],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'anniversary_date' => ['nullable', 'date', 'before_or_equal:today'],
            'whatsapp_marketing_consent' => ['sometimes', 'boolean'],
            'whatsapp_consent_source' => ['nullable', 'string', 'max:80'],
            'phone_country_iso_code' => 'required|string|max:3',
            'phone' => [
                'bail',
                'required',
                'phone:phone_country_iso_code',
                $this->uniqueActiveUserValue('phone'),
            ],
            'is_active' => 'required|boolean',
        ];
    }

    /** {@inheritDoc} */
    public function validationData(): array
    {
        $data = parent::validationData();
        $phone = $this->input('phone');
        $phoneCountryIsoCode = $this->input('phone_country_iso_code');

        if (! is_null($phone) && ! is_null($phoneCountryIsoCode)) {
            $data['phone'] = phone($phone, $phoneCountryIsoCode);
        }

        return $data;
    }

    /** {@inheritDoc} */
    protected function availableAttributes(): string
    {
        return 'user::attributes.users';
    }
}
