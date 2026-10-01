<?php

namespace Modules\Loyalty\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Loyalty\Enums\LoyaltyPromotionType;
use Modules\Support\Enums\Day;

/**
 * @property-read string|null $type
 */
class SaveLoyaltyPromotionRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string|max:500',
            ]),
            'loyalty_program_id' => "required|exists:loyalty_programs,id,deleted_at,NULL",
            'type' => ['required', Rule::enum(LoyaltyPromotionType::class)],
            'multiplier' => [
                Rule::requiredIf(fn() => in_array($this->type, [
                    LoyaltyPromotionType::Multiplier->value,
                    LoyaltyPromotionType::CategoryBoost->value,
                ])),
                'nullable',
                'numeric',
                'min:1',
                'max:10',
            ],
            'bonus_points' => [
                Rule::requiredIf(fn() => in_array($this->type, [
                    LoyaltyPromotionType::BonusPoints->value,
                    LoyaltyPromotionType::NewMember->value,
                ])),
                'nullable',
                'integer',
                'min:1',
                'max:1000000',
            ],
            'usage_limit' => 'nullable|integer|min:1|max:100000',
            'per_customer_limit' => 'nullable|integer|min:1|max:100000',
            'starts_at' => 'nullable|date|date_format:Y-m-d',
            'ends_at' => 'nullable|date|date_format:Y-m-d|after_or_equal:starts_at',
            'conditions' => 'nullable|array',
            'conditions.min_spend' => 'nullable|numeric|min:0',
            'conditions.available_days' => 'nullable|array',
            'conditions.available_days.*' => ['string', Rule::enum(Day::class)],
            'conditions.branch_ids' => 'nullable|array',
            'conditions.branch_ids.*' => 'bail|integer|exists:branches,id,deleted_at,NULL',
            'conditions.categories' => "required_if:type,category_boost|nullable|array",
            'conditions.categories.*' => "bail|required|string|exists:categories,slug,deleted_at,NULL",
            'conditions.valid_days' => "required_if:type,new_member|integer|min:1|max:360",
            'is_active' => 'required|boolean',
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return 'loyalty::attributes.loyalty_promotions';
    }
}
