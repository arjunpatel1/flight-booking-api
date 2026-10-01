<?php

namespace Modules\User\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClockInEmployeeAttendanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required_without:employee_shift_id', 'integer', 'exists:branches,id'],
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('can_login', false)
                    ->whereNull('deleted_at'),
            ],
            'employee_shift_id' => ['nullable', 'integer', 'exists:employee_shifts,id'],
            'clock_in_at' => ['nullable', 'date'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'meta' => ['nullable', 'array'],
        ];
    }

    /**
     * Laravel passes this straight to the validator factory, which requires an
     * array — returning the raw lang key raised a TypeError and turned every
     * clock-in into a 500. Mirrors SaveEmployeeShiftRequest.
     */
    public function attributes(): array
    {
        $attributes = __('user::attributes.employee_attendances');

        return is_array($attributes) ? $attributes : [];
    }
}
