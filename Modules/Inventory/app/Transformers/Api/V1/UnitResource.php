<?php

namespace Modules\Inventory\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\Unit;

/** @mixin Unit */
class UnitResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "symbol" => $this->symbol,
            "type" => $this->type->toTrans(),
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
