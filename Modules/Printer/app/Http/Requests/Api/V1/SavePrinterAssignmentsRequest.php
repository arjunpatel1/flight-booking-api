<?php

namespace Modules\Printer\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Printer\Enum\PrintContentType;

class SavePrinterAssignmentsRequest extends Request
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'users' => collect($this->input('users', []))
                ->filter(fn($row) => is_array($row) && (! empty($row['user_id']) || ! empty($row['printer_id']) || ! empty($row['print_type'])))
                ->values()
                ->all(),
            'roles' => collect($this->input('roles', []))
                ->filter(fn($row) => is_array($row) && (! empty($row['role_id']) || ! empty($row['printer_id']) || ! empty($row['print_type'])))
                ->values()
                ->all(),
        ]);
    }

    public function rules(): array
    {
        return [
            ...$this->getBranchRule(),
            'default_printer_id' => ['nullable', Rule::exists('printers', 'id')],
            'print_types' => ['nullable', 'array'],
            'print_types.*' => ['nullable', Rule::exists('printers', 'id')],
            'users' => ['nullable', 'array'],
            'users.*.user_id' => ['required', Rule::exists('users', 'id')],
            'users.*.print_type' => ['required', Rule::enum(PrintContentType::class)],
            'users.*.printer_id' => ['nullable', Rule::exists('printers', 'id')],
            'roles' => ['nullable', 'array'],
            'roles.*.role_id' => ['required', Rule::exists('roles', 'id')],
            'roles.*.print_type' => ['required', Rule::enum(PrintContentType::class)],
            'roles.*.printer_id' => ['nullable', Rule::exists('printers', 'id')],
        ];
    }

    protected function availableAttributes(): string
    {
        return 'printer::attributes.printer_assignments';
    }
}
