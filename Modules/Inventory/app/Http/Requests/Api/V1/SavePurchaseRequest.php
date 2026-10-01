<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;
use Modules\Inventory\Models\Purchase;

/** @mixin Purchase */
class SavePurchaseRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            ...$this->getBranchRule(),
            "supplier_id" => "bail|required|numeric|exists:suppliers,id,branch_id,$this->branch_id,deleted_at,NULL",
            "notes" => "nullable|string|max:1000",
            "expected_at" => "nullable|date|after_or_equal:today|date_format:Y-m-d",
            "discount" => "nullable|numeric|min:0|max:99999.9999",
            "tax" => "required|numeric|min:0|max:99999.9999",
            "items" => "required|array",
            "items.*.ingredient_id" => "required|numeric|exists:ingredients,id,branch_id,$this->branch_id,deleted_at,NULL",
            "items.*.quantity" => "required|numeric|min:0.01|max:99999.9999",
            "items.*.unit_cost" => "required|numeric|min:0|max:99999.9999",
        ];

        if ($this->route('id')) {
            $rules['items.*.id'] = "nullable|exists:purchase_items,id,purchase_id,{$this->route('id')}";
        }

        return $rules;
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "inventory::attributes.purchases";
    }
}
