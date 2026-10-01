<?php

namespace Modules\SeatingPlan\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SeatingPlan\Models\TableMerge;

/** @mixin TableMerge */
class TableMergeResource extends JsonResource
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
                "name" => $this->relationLoaded("branch") ? $this->branch->name : "",
            ],
            "created_by" => [
                "id" => $this->created_by,
                "name" => $this->relationLoaded("createdBy") ? $this->createdBy->name : "",
            ],
            "closed_by" => [
                "id" => $this->closed_by,
                "name" => $this->relationLoaded("closedBy") ? $this->closedBy?->name : "",
            ],
            "members" => TableMergeMemberResource::collection($this->whenLoaded("members")),
            "type" => $this->type->toTrans(),
            "closed_at" => dateTimeFormat($this->closed_at),
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
