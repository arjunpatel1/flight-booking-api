<?php

namespace Modules\Aggregator\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class AggregatorItemAvailabilityRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "items" => "required|array|min:1",
            "items.*.product_id" => "required|integer",
            "items.*.available" => "required|boolean",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "aggregator::attributes.item_availability";
    }
}
