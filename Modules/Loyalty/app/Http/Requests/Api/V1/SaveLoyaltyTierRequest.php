<?php

namespace Modules\Loyalty\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SaveLoyaltyTierRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules([
                'name' => 'required|string|max:100',
                'benefits' => 'nullable|string|max:5000',
            ]),
            'loyalty_program_id' => 'required|exists:loyalty_programs,id',
            'min_spend' => 'required|numeric|min:0|max:999999999.9999',
            'multiplier' => 'required|numeric|min:1|max:10',
            'order' => 'nullable|integer|min:1|max:50',
            "is_active" => "required|boolean",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "loyalty::attributes.loyalty_tiers";
    }
}
