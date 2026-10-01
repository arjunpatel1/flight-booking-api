<?php

namespace Modules\Report\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SaveWaiterSettlementRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "waiter_id" => "required|integer|exists:users,id",
            "business_date" => "required|date|date_format:Y-m-d",
            "settled_amount" => "required|numeric|min:0|max:99999999999999",
            "notes" => "nullable|string|max:1000",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "report::attributes.waiter_settlements";
    }
}
