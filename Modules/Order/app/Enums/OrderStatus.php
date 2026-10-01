<?php

namespace Modules\Order\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum OrderStatus: string
{
    use EnumTranslatable, EnumArrayable;

    case Pending = "pending";
    case Confirmed = "confirmed";
    case Preparing = "preparing";
    case Ready = "ready";
    case OutForDelivery = "out_for_delivery";
    case Served = "served";
    case Completed = "completed";
    case Cancelled = "cancelled";
    case Refunded = "refunded";
    case Merged = "merged";


    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "order::enums.order_statuses";
    }

    /**
     * Get enum as array value with trans
     *
     * @return array
     */
    public function toTrans(): array
    {
        return [
            "id" => $this->value,
            "name" => $this->trans(),
            "icon" => $this->icon(),
            "color" => $this->color(),
        ];
    }

    /**
     * Get icon
     *
     * @return string
     */
    public function icon(): string
    {
        return match ($this) {
            OrderStatus::Pending => "tabler-hourglass",
            OrderStatus::Confirmed => "tabler-check",
            OrderStatus::Preparing => "tabler-chef-hat",
            OrderStatus::Ready => "tabler-bell-ringing",
            OrderStatus::OutForDelivery => "tabler-moped",
            OrderStatus::Served => "tabler-brand-airtable",
            OrderStatus::Completed => "tabler-circle-check",
            OrderStatus::Cancelled => "tabler-x",
            OrderStatus::Refunded => "tabler-rotate-2",
            OrderStatus::Merged => "tabler-arrow-merge",
        };
    }


    /**
     * Get color
     *
     * @return string
     */
    public function color(): string
    {
        return match ($this) {
            OrderStatus::Pending => "#F59E0B",
            OrderStatus::Confirmed => "#3B82F6",
            OrderStatus::Preparing => "#8B5CF6",
            OrderStatus::Ready => "#10B981",
            OrderStatus::OutForDelivery => "#2563EB",
            OrderStatus::Served => "#14B8A6",
            OrderStatus::Completed => "#16A34A",
            OrderStatus::Cancelled => "#EF4444",
            OrderStatus::Refunded => "#6B7280",
            OrderStatus::Merged => "#d35400",
        };
    }
}
