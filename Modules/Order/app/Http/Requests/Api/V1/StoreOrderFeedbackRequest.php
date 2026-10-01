<?php

namespace Modules\Order\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;

class StoreOrderFeedbackRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Numeric IDs are enumerable and cannot authorize a public write.
            'order_id' => ['prohibited'],
            'order_reference' => ['required', 'string', 'max:120'],
            'feedback_token' => ['nullable', 'string', 'size:64'],
            'rating' => 'required|integer|min:1|max:5',
            'tags' => 'nullable|array|max:20',
            'tags.*' => 'required|string|max:60',
            'comment' => 'nullable|string|max:1000',
            'source' => ['nullable', 'string', Rule::in(['web', 'whatsapp', 'email', 'sms', 'push', 'qr'])],
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return 'order::attributes.feedback';
    }
}
