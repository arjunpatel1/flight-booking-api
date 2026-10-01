<?php

namespace Modules\Saas\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ConfigureApacheSslRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'mode' => ['nullable', 'string', 'in:platform_ssl,tenant_ssl'],
            'tenant_domain' => ['required_if:mode,tenant_ssl', 'nullable', 'string', 'max:255'],
            'api_domain' => ['required_unless:mode,tenant_ssl', 'nullable', 'string', 'max:255'],
            'root_domain' => ['required_unless:mode,tenant_ssl', 'nullable', 'string', 'max:255'],
            'tenant_domains' => ['required_unless:mode,tenant_ssl', 'nullable', 'string', 'max:2000'],
            'web_server' => ['nullable', 'string', 'in:apache,nginx'],
            'frontend_root' => ['nullable', 'string', 'max:1000'],
            'api_root' => ['nullable', 'string', 'max:1000'],
            'email' => ['required', 'email', 'max:255'],
            'apply' => ['nullable', 'boolean'],
            'install_packages' => ['nullable', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
