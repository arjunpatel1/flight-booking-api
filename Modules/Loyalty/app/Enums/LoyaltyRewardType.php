<?php

namespace Modules\Loyalty\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum LoyaltyRewardType: string
{
    use EnumArrayable, EnumTranslatable;

    case Discount = 'discount';
    case VoucherCode = 'voucher_code';
    case FreeItem = 'free_item';
//    case Cashback = 'cashback';
//    case GiftCard = 'gift_card';
    case TierUpgrade = 'tier_upgrade';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return 'loyalty::enums.loyalty_reward_types';
    }

    /**
     * Get color for each reward type
     *
     * @return string
     */
    public function color(): string
    {
        return match ($this) {
            self::Discount => '#3867D6',
            self::FreeItem => '#20BF6B',
//            self::Cashback => '#F7B731',
//            self::GiftCard => '#A55EEA',
            self::TierUpgrade => '#0FB9B1',
            self::VoucherCode => '#00cec9',
        };
    }

    /**
     * Get icon for each reward type
     *
     * @return string
     */
    public function icon(): string
    {
        return match ($this) {
            self::Discount => 'tabler-circle-dashed-percentage',
            self::FreeItem => 'tabler-gift',
//            self::Cashback => 'tabler-wallet',
//            self::GiftCard => 'tabler-credit-card',
            self::TierUpgrade => 'tabler-arrow-up-circle',
            self::VoucherCode => 'tabler-ticket',
        };
    }
}
