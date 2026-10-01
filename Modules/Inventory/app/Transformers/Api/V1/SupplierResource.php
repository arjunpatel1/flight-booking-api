<?php

namespace Modules\Inventory\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\Supplier;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "address" => $this->address,
            "phone" => $this->phone,
            "email" => $this->email,
            "created_at" => dateTimeFormat($this->created_at),
            "updated_at" => dateTimeFormat($this->updated_at),
        ];
    }
}
