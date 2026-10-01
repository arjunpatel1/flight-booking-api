<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\User;

/** @mixin User */
class OrderCustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();

        return [
            "id" => $this->id,
            "name" => $this->name,
            "profile_photo_url" => $this->profile_photo_url,
            "phone" => $this->phone,
            "email" => array_key_exists('email', $attributes) ? $this->email : null,
            "customer_since" => array_key_exists('created_at', $attributes) ? dateTimeFormat($this->created_at) : null,
        ];
    }
}
