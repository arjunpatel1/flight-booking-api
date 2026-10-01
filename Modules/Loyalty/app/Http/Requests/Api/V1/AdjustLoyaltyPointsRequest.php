<?php

namespace Modules\Loyalty\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class AdjustLoyaltyPointsRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "points" => "required|integer|not_in:0",
            "reason" => "nullable|string|max:500",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "loyalty::attributes.adjust_points";
    }
}
