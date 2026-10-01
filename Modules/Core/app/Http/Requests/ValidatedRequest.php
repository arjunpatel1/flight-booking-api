<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ValidatedRequest extends FormRequest
{
    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            \Modules\Support\ApiResponse::errors(
                errors: $validator->errors(),
                message: __('core::exceptions.unprocessable'),
                code: 422
            )
        );
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * Get validation rules with sanitization.
     *
     * @return array
     */
    abstract public function rules(): array;

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->sanitizeInput();
    }

    /**
     * Sanitize input data.
     *
     * @return void
     */
    protected function sanitizeInput(): void
    {
        $this->merge([
            'string_fields' => collect($this->all())
                ->filter(fn ($value) => is_string($value))
                ->map(fn ($value) => trim($value))
                ->toArray(),
        ]);
    }
}
