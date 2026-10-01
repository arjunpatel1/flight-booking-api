<?php

namespace Modules\Discount\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Support\InputLimit;
use Modules\Core\Http\Requests\Request;
use Modules\Order\Enums\OrderType;
use Modules\Support\Enums\Day;
use Modules\Support\Enums\PriceType;

class SaveDiscountRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            ...$this->getTranslationRules([
                "name" => "required|string|max:255",
                "description" => "nullable|string|max:1000",
            ]),
            ...$this->getBranchRule(true),
            "type" => ["required", Rule::enum(PriceType::class)],
            // Fixed-amount discounts were unbounded; the percent branch below
            // still narrows this to 0–100.
            "value" => ['required', ...InputLimit::money(min: 0.0001)],
            'is_active' => "required|boolean",
            'max_discount' => 'nullable|numeric|min:1|max:99999999999999',
            'minimum_spend' => 'nullable|numeric|min:0|max:99999999999999',
            'maximum_spend' => 'nullable|numeric|min:0|max:99999999999999|gte:minimum_spend',
            'usage_limit' => 'nullable|numeric|min:1|max:4294967295',
            'per_customer_limit' => 'nullable|numeric|min:1|max:4294967295|lte:usage_limit',
            'start_date' => "nullable|date|date_format:Y-m-d|after_or_equal:today",
            'end_date' => "nullable|date|date_format:Y-m-d|after_or_equal:start_date",
            'conditions' => "nullable|array",
            'conditions.order_types' => "nullable|array",
            'conditions.order_types.*' => ['required', 'string', Rule::enum(OrderType::class)],
            'conditions.available_days' => "nullable|array",
            'conditions.available_days.*' => ['required', 'string', Rule::enum(Day::class)],
            'conditions.categories' => "nullable|array",
            'conditions.categories.*' => "bail|required|string|exists:categories,slug",
            'conditions.products' => "nullable|array",
            'conditions.products.*' => 'bail|required|string|exists:products,sku',
        ];

        if ($this->input("type") == PriceType::Percent->value) {
            $rules['value'] = "required|numeric|min:0.0001|max:100";
        }

        return $rules;
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "discount::attributes.discounts";
    }
}
