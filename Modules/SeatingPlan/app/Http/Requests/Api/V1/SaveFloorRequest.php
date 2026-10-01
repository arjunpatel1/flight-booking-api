<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SaveFloorRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules([
                "name" => "required|string|max:255",
            ]),
            ...$this->getBranchRule(),
            "is_active" => "required|boolean",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "seatingplan::attributes.floors";
    }
}
