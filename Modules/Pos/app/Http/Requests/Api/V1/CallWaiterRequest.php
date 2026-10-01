<?php

namespace Modules\Pos\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;

class CallWaiterRequest extends Request
{
    public function rules(): array
    {
        return [
            // A sequential table id is an identifier, not authorization.
            // Public callers prove possession of the printed QR with the
            // table's non-guessable UUID capability.
            'table_id' => ['prohibited'],
            'table_token' => [
                'bail',
                'nullable',
                'uuid',
            ],
            'table_number' => ['bail', 'nullable', 'string', 'max:100'],
            'room_number' => ['bail', 'nullable', 'string', 'max:100'],
            'menu_slug' => ['bail', 'nullable', 'string', 'max:255'],
            'type' => [
                'bail',
                'required',
                'string',
                Rule::in(['assistance', 'bill', 'water', 'napkins']),
            ],
            'notes' => ['bail', 'nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->filled('table_token') && !$this->filled('table_number') && !$this->filled('room_number')) {
                $validator->errors()->add('table_token', __('pos::qr_order.no_table_assigned'));
            }

            if (!$this->filled('table_token') && !$this->filled('menu_slug')) {
                $validator->errors()->add('menu_slug', __('validation.required', ['attribute' => 'menu_slug']));
            }
        });
    }

    protected function availableAttributes(): string
    {
        return 'pos::qr_order.attributes';
    }
}
