<?php

namespace Modules\Voice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Voice\Models\VoiceTemplate;

class SaveVoiceTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('admin.voice.templates.edit');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $branchId = $this->resolveBranchId();

        return [
            'id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('voice_templates', 'id')->where('branch_id', $branchId),
            ],
            'template_name' => 'required|string|max:100',
            'template_text' => 'required|string|max:1000',
            'event_type' => ['required', 'string', Rule::in(VoiceTemplate::EVENT_TYPES)],
            'is_default' => 'sometimes|boolean',
            'priority' => 'sometimes|integer|min:0|max:2',
            'is_active' => 'sometimes|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'template_name.required' => 'Template name is required',
            'template_name.max' => 'Template name cannot exceed 100 characters',
            'template_text.required' => 'Template text is required',
            'template_text.max' => 'Template text cannot exceed 1000 characters',
            'event_type.required' => 'Event type is required',
            'event_type.in' => 'Invalid event type selected',
            'priority.min' => 'Priority must be between 0 and 2',
            'priority.max' => 'Priority must be between 0 and 2',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Sanitize template text to prevent XSS
        if ($this->has('template_text')) {
            $this->merge([
                'template_text' => strip_tags($this->input('template_text')),
            ]);
        }
    }

    private function resolveBranchId(): ?int
    {
        $user = $this->user();

        if (!$user) {
            return null;
        }

        if ($user->assignedToBranch()) {
            return (int) $user->branch_id;
        }

        return $user->effective_branch?->id ? (int) $user->effective_branch->id : null;
    }
}
