<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SaveTablePositionsRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "positions" => "required|array|min:1|max:500",
            "positions.*.id" => "required|integer|distinct|exists:tables,id,deleted_at,NULL",
            "positions.*.pos_x" => "required|numeric|min:0|max:10000",
            "positions.*.pos_y" => "required|numeric|min:0|max:10000",
            "positions.*.rotation" => "required|numeric|min:0|max:359.99",
            "positions.*.scale" => "required|numeric|min:0.5|max:2",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "seatingplan::attributes.tables";
    }
}
