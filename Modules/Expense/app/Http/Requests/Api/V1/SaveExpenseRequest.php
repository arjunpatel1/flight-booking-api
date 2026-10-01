<?php

namespace Modules\Expense\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SaveExpenseRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getBranchRule(true),
            "expense_category_id" => "required|integer|exists:expense_categories,id",
            "amount" => "required|numeric|min:0|max:99999999999999",
            "expense_date" => "required|date|date_format:Y-m-d",
            "description" => "nullable|string|max:1000",
            "reference" => "nullable|string|max:255",
            "notes" => "nullable|string|max:1000",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "expense::attributes.expenses";
    }
}
