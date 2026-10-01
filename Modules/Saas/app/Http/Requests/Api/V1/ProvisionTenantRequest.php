<?php

namespace Modules\Saas\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProvisionTenantRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('domain')) {
            $this->merge(['domain' => $this->normalizeDomain((string) $this->input('domain'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('tenants', 'slug')],
            'domain' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/',
                Rule::unique('tenants', 'domain'),
            ],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'admin_name' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'staff_name' => ['nullable', 'string', 'max:255', 'required_with:staff_email'],
            'staff_email' => ['nullable', 'email', 'max:255', 'different:email', 'required_with:staff_name'],
            'staff_password' => ['nullable', 'string', 'min:8', 'max:255', 'required_with:staff_email'],
            'staff_role' => ['nullable', 'string', Rule::in(['manager', 'cashier', 'kitchen', 'waiter'])],
            'plan' => ['nullable', 'string', 'max:120'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'currency' => ['nullable', 'string', 'size:3'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'primary_color' => ['nullable', 'string', 'max:20'],
            'secondary_color' => ['nullable', 'string', 'max:20'],
            'logo_url' => ['nullable', 'url', 'max:1000'],
            'gst_number' => ['nullable', 'string', 'max:40'],
            'server_automation' => ['nullable', 'array'],
            'server_automation.web_server' => ['nullable', 'string', 'in:apache,nginx'],
            'server_automation.apply' => ['nullable', 'boolean'],
            'server_automation.api_domain' => ['nullable', 'string', 'max:255'],
            'server_automation.root_domain' => ['nullable', 'string', 'max:255'],
            'server_automation.tenant_domains' => ['nullable', 'string', 'max:2000'],
            'server_automation.frontend_root' => ['nullable', 'string', 'max:1000'],
            'server_automation.api_root' => ['nullable', 'string', 'max:1000'],
            'server_automation.email' => ['nullable', 'email', 'max:255'],
            'server_automation.install_packages' => ['nullable', 'boolean'],
            'white_label_build' => ['nullable', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'domain.regex' => 'Enter a valid domain only, for example redison-blu.nexdine.myteknoland.net. Do not include https://, paths, spaces, or uppercase branding text.',
        ];
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
        return trim($domain, ". \t\n\r\0\x0B");
    }
}
