<?php

namespace Modules\Menu\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Menu\Models\OnlineMenu;

/** @mixin OnlineMenu */
class OnlineMenuResource extends JsonResource
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
                "name" => $this->relationLoaded("branch") ? $this->branch->name : "",
            ],
            "hotel" => [
                "id" => $this->hotel_branch_id,
                "name" => $this->relationLoaded("hotelBranch") ? $this->hotelBranch?->name : "",
            ],
            "menu" => [
                "id" => $this->menu_id,
                "name" => is_null($this->menu_id)
                    ? __("menu::online_menus.active_menu")
                    : ($this->relationLoaded("menu") ? $this->menu?->name : ""),
            ],
            "slug" => $this->slug,
            "is_active" => $this->is_active,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
