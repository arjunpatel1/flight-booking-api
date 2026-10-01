<?php

namespace Modules\Voucher\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Support\Enums\DateTimeFormat;
use Modules\Voucher\Models\Voucher;

/** @mixin Voucher */
class ShowVoucherResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new VoucherResource($this))->resolve(),
            "value" => $this->value,
            "minimum_spend" => $this->minimum_spend,
            "maximum_spend" => $this->maximum_spend,
            "max_discount" => $this->max_discount,
            "conditions" => $this->conditions,
            "usage_limit" => $this->usage_limit,
            "per_customer_limit" => $this->per_customer_limit,
            "meta" => $this->meta,
            "start_date" => dateTimeFormat($this->start_date, DateTimeFormat::Date),
            "end_date" => dateTimeFormat($this->end_date, DateTimeFormat::Date),
        ];
    }
}
