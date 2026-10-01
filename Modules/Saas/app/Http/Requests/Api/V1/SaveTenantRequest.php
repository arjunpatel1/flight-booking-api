<?php

namespace Modules\Saas\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTenantRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = $this->route('id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('tenants', 'slug')->ignore($tenantId)],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'domain')->ignore($tenantId)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'settings' => ['nullable', 'array'],
            'is_active' => ['required', 'boolean'],
            'owner_login_email' => ['nullable', 'email', 'max:255'],
            'owner_login_username' => ['nullable', 'string', 'max:120'],
            'owner_login_password' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
