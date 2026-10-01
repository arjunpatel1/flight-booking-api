<?php

namespace Modules\Pos\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pos\Models\PosSession;

/** @mixin PosSession */
class PosSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "pos_register" => [
                "id" => $this->pos_register_id,
                "name" => $this->relationLoaded("posRegister") ? $this->posRegister->name : "",
            ],
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch->name : "",
            ],
            "opened_by" => [
                "id" => $this->opened_by,
                "name" => $this->relationLoaded("openedBy") ? $this->openedBy->name : "",
            ],
            "closed_by" => [
                "id" => $this->closed_by,
                "name" => $this->relationLoaded("closedBy") ? $this->closedBy?->name : "",
            ],
            "status" => $this->status->toTrans(),
            "opened_at" => dateTimeFormat($this->opened_at),
            "closed_at" => dateTimeFormat($this->closed_at),
        ];
    }
}
