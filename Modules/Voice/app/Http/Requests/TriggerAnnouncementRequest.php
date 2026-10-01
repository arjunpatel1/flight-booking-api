<?php

namespace Modules\Voice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Voice\Models\VoiceTemplate;

class TriggerAnnouncementRequest extends FormRequest
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
        $branchId = $this->resolveBranchId();

        return [
            'order_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('orders', 'id')->where('branch_id', $branchId),
            ],
            'event_type' => ['required', 'string', Rule::in(VoiceTemplate::EVENT_TYPES)],
            'variables' => 'sometimes|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'event_type.required' => 'Event type is required',
            'event_type.in' => 'Invalid event type selected',
            'order_id.integer' => 'Order ID must be an integer',
        ];
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
