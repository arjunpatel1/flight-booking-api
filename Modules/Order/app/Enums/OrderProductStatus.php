<?php

namespace Modules\Order\Enums;

use Modules\Support\Traits\EnumArrayable;
use Modules\Support\Traits\EnumTranslatable;

enum OrderProductStatus: string
{
    use EnumTranslatable, EnumArrayable;

    case Pending = "pending";
    case Preparing = "preparing";
    case Ready = "ready";
    case Served = "served";
    case Cancelled = "cancelled";
    case Refunded = "refunded";


    /** @inheritDoc */
    public static function getTransKey(): string
    {
        return "order::enums.order_product_statuses";
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
            OrderProductStatus::Pending => "tabler-hourglass",
            OrderProductStatus::Preparing => "tabler-chef-hat",
            OrderProductStatus::Ready => "tabler-bell-ringing",
            OrderProductStatus::Served => "tabler-brand-airtable",
            OrderProductStatus::Cancelled => "tabler-x",
            OrderProductStatus::Refunded => "tabler-rotate-2",
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
            OrderProductStatus::Pending => "#F59E0B",
            OrderProductStatus::Preparing => "#8B5CF6",
            OrderProductStatus::Ready => "#10B981",
            OrderProductStatus::Served => "#14B8A6",
            OrderProductStatus::Cancelled => "#EF4444",
            OrderProductStatus::Refunded => "#6B7280",
        };
    }

    /**
     * Get order product next status
     *
     * @return OrderProductStatus|null
     */
    public function nextStatus(): ?OrderProductStatus
    {
        return match ($this) {
            OrderProductStatus::Pending => OrderProductStatus::Preparing,
            OrderProductStatus::Preparing => OrderProductStatus::Ready,
            OrderProductStatus::Ready => OrderProductStatus::Served,
            default => null,
        };
    }

}
