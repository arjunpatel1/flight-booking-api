<?php

namespace Modules\User\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;

class SaveEmployeeCompensationRequest extends Request
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'pay_type' => ['required', Rule::in(['monthly', 'hourly'])],
            'base_rate' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'overtime_rate' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'standard_daily_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function availableAttributes(): string
    {
        return 'user::attributes.employee_compensations';
    }
}
