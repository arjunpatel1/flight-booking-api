<?php

namespace Modules\Voice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveVoiceSettingsRequest extends FormRequest
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
            'voice_enabled' => 'sometimes|boolean',
            'voice_gender' => 'sometimes|in:Male,Female',
            'voice_rate' => 'sometimes|integer|min:-10|max:10',
            'voice_volume' => 'sometimes|integer|min:0|max:100',
            'selected_device_id' => 'sometimes|nullable|string|max:255',
            'selected_device_name' => 'sometimes|nullable|string|max:255',
            'test_voice_enabled' => 'sometimes|boolean',
            'delay_threshold_minutes' => 'sometimes|integer|min:5|max:120',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'voice_gender.in' => 'Voice gender must be Male or Female',
            'voice_rate.min' => 'Voice rate must be between -10 and 10',
            'voice_rate.max' => 'Voice rate must be between -10 and 10',
            'voice_volume.min' => 'Voice volume must be between 0 and 100',
            'voice_volume.max' => 'Voice volume must be between 0 and 100',
            'delay_threshold_minutes.min' => 'Delay threshold must be at least 5 minutes',
            'delay_threshold_minutes.max' => 'Delay threshold cannot exceed 120 minutes',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Sanitize device name to prevent XSS
        if ($this->has('selected_device_name')) {
            $this->merge([
                'selected_device_name' => strip_tags($this->input('selected_device_name')),
            ]);
        }
    }
}
