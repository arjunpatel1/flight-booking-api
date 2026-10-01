<?php

namespace Modules\SeatingPlan\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SeatingPlan\Models\TableMergeMember;

/** @mixin TableMergeMember */
class TableMergeMemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "table" => [
                "id" => $this->table_id,
                "name" => $this->relationLoaded("table") ? $this->table->name : "",
            ],
            "is_main" => $this->is_main
        ];
    }
}
