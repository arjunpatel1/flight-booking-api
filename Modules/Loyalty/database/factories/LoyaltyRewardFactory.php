<?php

namespace Modules\Loyalty\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Loyalty\Enums\LoyaltyRewardType;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyReward;
use Modules\Loyalty\Models\LoyaltyTier;
use Modules\Product\Models\Product;
use Modules\Support\Enums\Day;
use Modules\Support\Enums\PriceType;
use Str;

/**
 * @extends Factory<LoyaltyReward>
 */
class LoyaltyRewardFactory extends Factory
{
    protected $model = LoyaltyReward::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(LoyaltyRewardType::values());
        $valueType = $this->faker->randomElement(PriceType::values());
        $nameEn = match ($type) {
            LoyaltyRewardType::Discount->value => 'Discount Reward',
            LoyaltyRewardType::VoucherCode->value => 'Voucher Reward',
            LoyaltyRewardType::FreeItem->value => 'Free Item Reward',
            LoyaltyRewardType::TierUpgrade->value => 'Tier Upgrade Reward',
            default => 'Loyalty Reward',
        };

        $program = LoyaltyProgram::query()->inRandomOrder()->first() ?? LoyaltyProgram::factory()->create();
        $tier = LoyaltyTier::query()->where('loyalty_program_id', $program->id)->inRandomOrder()->first();

        $meta = $this->buildMetaForType($type, $tier);

        return [
            'loyalty_program_id' => $program->id,
            'loyalty_tier_id' => $this->faker->boolean(30) ? $tier?->id : null,
            'name' => [
                'en' => $nameEn,
                'ar' => $this->faker->word(),
            ],
            'description' => [
                'en' => 'A reward for loyal customers.',
                'ar' => 'مكافأة للعملاء المخلصين.',
            ],
            'type' => $type,
            'points_cost' => $this->faker->numberBetween(100, 1000),
            'value' => $valueType === PriceType::Percent->value
                ? $this->faker->numberBetween(5, 30)
                : $this->faker->numberBetween(5, 50),
            'value_type' => $valueType,
            'max_redemptions_per_order' => 1,
            'usage_limit' => $this->faker->optional()->numberBetween(50, 500),
            'per_customer_limit' => $this->faker->optional()->numberBetween(1, 10),
            'conditions' => $this->buildConditions(),
            'meta' => $meta,
            'total_redeemed' => 0,
            'total_customers' => 0,
            'is_active' => true,
            'order' => $this->faker->numberBetween(1, 50),
            'starts_at' => now()->subDays($this->faker->numberBetween(0, 10)),
            'ends_at' => now()->addDays($this->faker->numberBetween(10, 60)),
        ];
    }

    /**
     * Build meta-data based on reward type.
     */
    protected function buildMetaForType(string $type, ?LoyaltyTier $tier = null): array
    {
        return match ($type) {
            LoyaltyRewardType::Discount->value,
            LoyaltyRewardType::VoucherCode->value => [
                'min_order_total' => $this->faker->optional()->numberBetween(50, 200),
                'max_order_total' => $this->faker->optional()->numberBetween(200, 1000),
                'max_discount' => $this->faker->optional()->numberBetween(20, 100),
                'expires_in_days' => $this->faker->numberBetween(15, 90),
                'usage_limit' => $this->faker->optional()->numberBetween(10, 500),
                'code_prefix' => $type === LoyaltyRewardType::VoucherCode->value
                    ? strtoupper(Str::random(3))
                    : null,
            ],
            'free_item' => [
                'product_sku' => Product::query()
                    ->whereNotNull('sku')
                    ->inRandomOrder()
                    ->first()->sku,
                'quantity' => $this->faker->numberBetween(1, 3),
            ],
            'tier_upgrade' => [
                'target_tier' => $tier?->id ?? 1,
            ],
            default => [],
        };
    }

    /**
     * Build dynamic conditions for the reward.
     */
    protected function buildConditions(): array
    {
        $days = Day::values();
        $branches = $this->faker->randomElements(range(1, 5), $this->faker->numberBetween(1, 3));

        return [
            'min_spend' => $this->faker->optional()->numberBetween(50, 2000),
            'available_days' => $this->faker->boolean(70)
                ? $this->faker->randomElements($days, $this->faker->numberBetween(2, 7))
                : null,
            'branch_ids' => $this->faker->boolean(60) ? $branches : null,
        ];
    }
}

