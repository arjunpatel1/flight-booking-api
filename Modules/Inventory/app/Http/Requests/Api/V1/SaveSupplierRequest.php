<?php

namespace Modules\Inventory\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SaveSupplierRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "name" => "required|string|max:255",
            ...$this->getBranchRule(),
            "address" => "nullable|string|max:255",
            "phone" => "nullable|string|max:255",
            "email" => "nullable|string|email|max:255",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "inventory::attributes.suppliers";
    }
}
