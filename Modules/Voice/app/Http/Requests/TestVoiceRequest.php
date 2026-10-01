<?php

namespace Modules\Voice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TestVoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('admin.voice.settings.edit');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'message' => 'required|string|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Message is required',
            'message.max' => 'Message cannot exceed 500 characters',
        ];
    }
}
