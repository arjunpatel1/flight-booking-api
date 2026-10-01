<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

/**
 * @property int|null $branch_id
 */
class SaveZoneRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules(["name" => "required|string|max:255"]),
            ...$this->getBranchRule(),
            "floor_id" => "bail|required|numeric|exists:floors,id,deleted_at,NULL,is_active,1,branch_id," . $this->branch_id,
            "price_type_id" => "nullable|integer|exists:price_types,id,deleted_at,NULL",
            "is_active" => "required|boolean",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "seatingplan::attributes.zones";
    }
}
