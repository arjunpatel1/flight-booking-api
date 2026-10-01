<?php

namespace Modules\User\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ClockOutEmployeeAttendanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'clock_out_at' => ['nullable', 'date'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Must be an array for the validator factory; see the matching note on
     * ClockInEmployeeAttendanceRequest.
     */
    public function attributes(): array
    {
        $attributes = __('user::attributes.employee_attendances');

        return is_array($attributes) ? $attributes : [];
    }
}
