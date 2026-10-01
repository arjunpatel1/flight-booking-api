<?php

namespace Modules\Loyalty\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SaveLoyaltyProgramRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules([
                "name" => "required|string|min:1|max:100",
            ]),
            'earning_rate' => 'required|numeric|min:0.0001|max:9999.9999',
            'redemption_rate' => 'required|numeric|min:0.0001|max:1',
            'min_redeem_points' => 'required|integer|min:1|max:100000',
            'points_expire_after' => 'nullable|integer|min:1|max:1825',
            "is_active" => "required|boolean",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "loyalty::attributes.loyalty_programs";
    }
}
