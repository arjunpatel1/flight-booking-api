<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Inventory\Enums\UnitType;

class SaveUnitRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules([
                "name" => "required|string|max:255",
                "symbol" => "required|string|max:10",
            ]),
            "type" => ["required", Rule::enum(UnitType::class)],
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "inventory::attributes.units";
    }
}
