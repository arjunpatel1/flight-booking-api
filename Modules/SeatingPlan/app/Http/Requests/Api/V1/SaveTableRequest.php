<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\SeatingPlan\Enums\TableShape;

/**
 * @property int|null $branch_id
 * @property int|null $floor_id
 */
class SaveTableRequest extends Request
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
            "zone_id" => "bail|required|numeric|exists:zones,id,deleted_at,NULL,is_active,1,branch_id," . $this->branch_id . ",floor_id," . $this->floor_id,
            "is_active" => "required|boolean",
            "capacity" => "required|numeric|min:1|max:100",
            "shape" => ["required", Rule::enum(TableShape::class)],
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "seatingplan::attributes.tables";
    }
}
