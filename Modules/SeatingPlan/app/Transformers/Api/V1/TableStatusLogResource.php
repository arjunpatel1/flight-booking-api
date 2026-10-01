<?php

namespace Modules\SeatingPlan\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SeatingPlan\Models\TableStatusLog;

/** @mixin TableStatusLog */
class TableStatusLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "changed_by" => [
                "id" => $this->changed_by,
                "name" => $this->relationLoaded("changedBy") ? ($this->changedBy?->name ?: "System") : "System"
            ],
            "status" => $this->status->toTrans(),
            "note" => $this->note,
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
