<?php

namespace Modules\Pos\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;

class SaveKitchenStationRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $user = auth()->user();

        return [
            'name' => ['required', 'max:255'],
            'description' => ['nullable', 'max:1000'],
            'branch_id' => [
                $user?->assignedToBranch() ? 'nullable' : 'required',
                'integer',
                Rule::exists('branches', 'id')->whereNull('deleted_at'),
            ],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'max_concurrent_items' => ['nullable', 'integer', 'min:1', 'max:999'],
            'printer_id' => [
                'nullable',
                'integer',
                Rule::exists('printers', 'id')->whereNull('deleted_at'),
            ],
            'color' => ['nullable', 'string', 'max:20'],
            'sound_enabled' => ['required', 'boolean'],
            'auto_bump_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'categories' => ['nullable', 'array'],
            'categories.*' => [
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Get available attributes.
     */
    protected function availableAttributes(): string
    {
        return 'pos::attributes.kitchen_stations';
    }
}
