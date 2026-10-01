<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\StockMovement;

/** @mixin StockMovement */
class SaveStockMovementRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getBranchRule(),
            "ingredient_id" => "bail|required|numeric|exists:ingredients,id,deleted_at,NULL",
            "type" => ["required", Rule::enum(StockMovementType::class)],
            'quantity' => "required|numeric|min:0.01",
            'note' => "nullable|string|max:1000"
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "inventory::attributes.stock_movements";
    }
}
