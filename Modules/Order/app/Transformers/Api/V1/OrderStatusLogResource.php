<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\OrderStatusLog;

/** @mixin OrderStatusLog */
class OrderStatusLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "status" => $this->status->toTrans(),
            "reason" => [
                "id" => $this->reason_id,
                "name" => $this->relationLoaded("reason") ? $this->reason?->name : null,
            ],
            "changed_by" => [
                "id" => $this->changed_by,
                "name" => $this->relationLoaded("changedBy") ? $this->changedBy?->name : null,
            ],
            "note" => $this->note,
            "created_at" => dateTimeFormat($this->created_at),
            // Machine-readable companion retained alongside the localized
            // display value so mobile/web clients can sort and format history
            // without guessing the tenant's date format.
            "created_at_iso" => $this->created_at?->toISOString(),
        ];
    }
}
