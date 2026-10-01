<?php

namespace Modules\Report\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Report\Models\WaiterCollectionSettlement;
use Modules\Support\Money;

/** @mixin WaiterCollectionSettlement */
class WaiterSettlementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "waiter" => [
                "id" => $this->waiter_id,
                "name" => $this->relationLoaded("waiter") ? $this->waiter?->name : null,
            ],
            "settled_by" => [
                "id" => $this->settled_by,
                "name" => $this->relationLoaded("settledBy") ? $this->settledBy?->name : null,
            ],
            "business_date" => dateTimeFormat($this->business_date),
            "business_date_iso" => $this->business_date?->toDateString(),
            "expected_amount" => (new Money((float) $this->expected_amount, $this->currency))->toArray(),
            "settled_amount" => (new Money((float) $this->settled_amount, $this->currency))->toArray(),
            "difference_amount" => (new Money((float) $this->difference_amount, $this->currency))->toArray(),
            "status" => $this->status,
            "notes" => $this->notes,
            "settled_at" => dateTimeFormat($this->settled_at),
        ];
    }
}
