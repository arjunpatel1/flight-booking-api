<?php

namespace Modules\Pos\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pos\Models\PosCashMovement;

/** @mixin PosCashMovement */
class PosCashMovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "pos_register" => [
                "id" => $this->pos_register_id,
                "name" => $this->relationLoaded("posRegister") ? $this->posRegister?->name : "",
            ],
            "direction" => $this->direction->toTrans(),
            "reason" => $this->reason->toTrans(),
            "amount" => $this->amount->withConvertedDefaultCurrency($this->currency_rate),
            "balance_before" => $this->balance_before->withConvertedDefaultCurrency($this->currency_rate),
            "balance_after" => $this->balance_after->withConvertedDefaultCurrency($this->currency_rate),
            "occurred_at" => dateTimeFormat($this->occurred_at),
        ];
    }
}
