<?php

namespace Modules\Loyalty\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Loyalty\Enums\LoyaltyRewardType;
use Modules\Support\Enums\Day;
use Modules\Support\Enums\PriceType;

/**
 * @property-read string|null $type
 * @property-read int|null $loyalty_program_id
 */
class SaveLoyaltyRewardRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules([
                "name" => "required|string|max:255",
                "description" => "required|string|max:500",
            ]),
            'loyalty_program_id' => "required|exists:loyalty_programs,id,deleted_at,NULL",
            'loyalty_tier_id' => "nullable|exists:loyalty_tiers,id,deleted_at,NULL,is_active,1",
            'type' => ['required', Rule::enum(LoyaltyRewardType::class)],
            'points_cost' => "required|integer|min:1|max:100000000",
            'value' => [
                Rule::requiredIf(fn() => in_array($this->type, [
                    LoyaltyRewardType::Discount->value,
//                    LoyaltyRewardType::Cashback->value,
//                    LoyaltyRewardType::GiftCard->value,
                    LoyaltyRewardType::VoucherCode->value,
                ])),
                'nullable',
                'numeric',
            ],
            'value_type' => [
                Rule::requiredIf(fn() => in_array($this->type, [
                    LoyaltyRewardType::Discount->value,
//                    LoyaltyRewardType::Cashback->value,
//                    LoyaltyRewardType::GiftCard->value,
                    LoyaltyRewardType::VoucherCode->value,
                ])),
                'nullable',
                Rule::enum(PriceType::class)
            ],
            'max_redemptions_per_order' => "nullable|integer|min:1|max:1",
            'usage_limit' => 'nullable|integer|min:1|max:100000',
            'per_customer_limit' => 'nullable|integer|min:1|max:100000',
            'starts_at' => 'nullable|date|date_format:Y-m-d',
            'ends_at' => 'nullable|date|date_format:Y-m-d|after_or_equal:starts_at',
            'conditions' => 'nullable|array',
            'conditions.min_spend' => 'nullable|numeric|min:0',
            'conditions.branch_ids' => 'nullable|array',
            'conditions.branch_ids.*' => 'bail|integer|exists:branches,id,deleted_at,NULL',
            'conditions.available_days' => 'nullable|array',
            'conditions.available_days.*' => ['string', Rule::enum(Day::class)],
            'meta' => 'nullable|array',
            ...$this->getDiscountMetaRules(),
            ...$this->getFreeItemMetaRules(),
            ...$this->getTierUpgradeMetaRules(),
            'is_active' => 'required|boolean',
        ];
    }

    /**
     * Get discount meta rules
     *
     * @return array|string[]
     */
    protected function getDiscountMetaRules(): array
    {
        if (!in_array($this->type, [LoyaltyRewardType::Discount->value, LoyaltyRewardType::VoucherCode->value])) {
            return [];
        }

        return [
            "meta.min_order_total" => 'nullable|numeric|min:0.0001|max:99999999999999',
            "meta.max_order_total" => 'nullable|numeric|min:0.0001|max:99999999999999|gte:meta.min_order_total',
            "meta.max_discount" => 'nullable|numeric|min:0.0001|max:99999999999999',
            "meta.expires_in_days" => "nullable|min:1|max:360",
            'meta.code_prefix' => "nullable|string|max:50",
        ];
    }

    /**
     * Get discount meta rules
     *
     * @return array|string[]
     */
    protected function getFreeItemMetaRules(): array
    {
        if ($this->type != LoyaltyRewardType::FreeItem->value) {
            return [];
        }

        return [
            "meta.product_sku" => 'required|string|max:255|exists:products,sku,deleted_at,NULL',
            "meta.quantity" => "required|numeric|min:1|max:10",
        ];
    }

    /**
     * Get tier upgrade meta rules
     *
     * @return array|string[]
     */
    protected function getTierUpgradeMetaRules(): array
    {
        if ($this->type != LoyaltyRewardType::TierUpgrade->value) {
            return [];
        }

        return [
            'meta.target_tier' => "bail|required|integer|exists:loyalty_tiers,id,deleted_at,NULL,is_active,1,loyalty_program_id,$this->loyalty_program_id",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "loyalty::attributes.loyalty_rewards";
    }
}
