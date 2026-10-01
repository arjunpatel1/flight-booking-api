<?php

namespace Modules\Import\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Import\Enums\ImportType;

class StoreImportRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $actor = auth()->user();
        if (! $actor?->assignedToTenant() || $actor->isSuperAdmin()) {
            return;
        }

        $options = (array) $this->input('options', []);
        $options['tenant_id'] = $actor->tenantId();
        if ($actor->assignedToBranch()) {
            $options['branch_id'] = $actor->branch_id;
        }

        $this->merge(['options' => $options]);
    }

    public function rules(): array
    {
        $tenantId = (int) data_get($this->input('options', []), 'tenant_id', 0);

        return [
            'type' => ['required', Rule::enum(ImportType::class)],
            'file' => ['required', 'file', 'mimes:csv,txt,xls,xlsx', 'max:10240'],
            'options' => ['nullable', 'array'],
            'options.branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')
                    ->whereNull('deleted_at')
                    ->when($tenantId > 0, fn ($rule) => $rule->where('tenant_id', $tenantId)),
            ],
            'options.menu_id' => [
                'nullable',
                'integer',
                Rule::exists('menus', 'id')
                    ->whereNull('deleted_at')
                    ->when($tenantId > 0, fn ($rule) => $rule->where(
                        fn ($query) => $query->whereIn(
                            'branch_id',
                            fn ($branches) => $branches->select('id')->from('branches')->where('tenant_id', $tenantId)
                        )
                    )),
            ],
            'options.role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'options.tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ];
    }

    protected function availableAttributes(): string
    {
        return 'import::imports.attributes';
    }
}
