<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SaveIngredientRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules(["name" => "required|string|max:255"]),
            ...$this->getBranchRule(),
            "unit_id" => "bail|required|numeric|exists:units,id,deleted_at,NULL",
            'cost_per_unit' => "required|numeric|min:0|max:99999.9999",
            'alert_quantity' => "required|numeric|min:0|max:100000",
            "is_returnable" => "required|boolean",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "inventory::attributes.ingredients";
    }
}
