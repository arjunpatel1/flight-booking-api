<?php

namespace Modules\User\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\User;

/** @mixin User */
class UserSimpleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "username" => $this->username,
            "email" => $this->email,
            "role" => isset($this->roles[0])
                ? [
                    "id" => $this->roles[0]->id,
                    "key" => $this->roles[0]->name,
                    "name" => $this->roles[0]->name,
                    "display_name" => $this->roles[0]->display_name
                ] : null,
            "profile_photo_url" => $this->profile_photo_url,
        ];
    }
}
