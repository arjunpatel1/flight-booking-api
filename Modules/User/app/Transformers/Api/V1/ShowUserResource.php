<?php

namespace Modules\User\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\User;

/** @mixin User */
class ShowUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            ...(new UserResource($user))->resolve($request),
            "category_slugs" => $user->category_slugs ?? [],
            "printer" => [
                "id" => $user->printer_id,
                "name" => $user->relationLoaded("printer") ? $user->printer?->name : null,
            ]
        ];
    }
}
