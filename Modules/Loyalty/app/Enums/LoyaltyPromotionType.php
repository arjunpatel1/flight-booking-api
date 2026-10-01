<?php

namespace Modules\Loyalty\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum LoyaltyPromotionType: string
{
    use EnumArrayable, EnumTranslatable;

    case Multiplier = 'multiplier';
    case BonusPoints = 'bonus_points';
    case CategoryBoost = 'category_boost';
    case NewMember = 'new_member';

    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return 'loyalty::enums.loyalty_promotion_types';
    }
    
    /**
     * Tabler icon for UI display
     *
     * @return string
     */
    public function icon(): string
    {
        return match ($this) {
            self::Multiplier => 'tabler-arrow-big-up-lines',
            self::BonusPoints => 'tabler-coin',
            self::CategoryBoost => 'tabler-category-2',
            self::NewMember => 'tabler-user-plus',
        };
    }

    /**
     * Default UI color for each type
     *
     * @return string
     */
    public function color(): string
    {
        return match ($this) {
            self::Multiplier => '#3867D6',
            self::BonusPoints => '#20BF6B',
            self::CategoryBoost => '#F7B731',
            self::NewMember => '#0FB9B1',
        };
    }
}
