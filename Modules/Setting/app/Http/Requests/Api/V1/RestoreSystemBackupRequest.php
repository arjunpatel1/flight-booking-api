<?php

namespace Modules\Setting\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RestoreSystemBackupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'in:RESTORE'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
