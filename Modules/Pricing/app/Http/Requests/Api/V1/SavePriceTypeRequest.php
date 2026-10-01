<?php

namespace Modules\Pricing\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Pricing\Enums\PriceTypeRuleType;

class SavePriceTypeRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules(['name' => 'required|string|max:255']),
            ...$this->getTranslationRules(['description' => 'nullable|string|max:1000']),
            'rule_type' => ['required', Rule::enum(PriceTypeRuleType::class)],
            'rule_value' => [
                'required',
                'numeric',
                'min:0',
                Rule::when($this->rule_type === PriceTypeRuleType::Percent->value, ['max:100']),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return 'pricing::attributes.price_types';
    }
}
