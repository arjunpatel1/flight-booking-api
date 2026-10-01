<?php

namespace Modules\Loyalty\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;

class AvailableGiftsRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "customer_id" => [
                "required",
                "integer",
                Rule::exists('users', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
            ]
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "loyalty::attributes.available_gifts";
    }
}
