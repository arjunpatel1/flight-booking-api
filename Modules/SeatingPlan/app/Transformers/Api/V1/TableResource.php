<?php

namespace Modules\SeatingPlan\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SeatingPlan\Models\Table;

/** @mixin Table */
class TableResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $onlineMenu = $this->relationLoaded('branch')
            ? $this->branch?->onlineMenus?->firstWhere('is_active', true)
            : null;
        $tenant = $this->relationLoaded('branch') && $this->branch?->relationLoaded('tenant')
            ? $this->branch->tenant
            : null;
        $tenantSettings = (array) ($tenant?->settings ?? []);
        $logoUrl = data_get($tenantSettings, 'branding.logo_url')
            ?? data_get($tenantSettings, 'brand.logo_url')
            ?? data_get($tenantSettings, 'logo_url')
            ?? data_get($tenantSettings, 'logo')
            ?? data_get($tenantSettings, 'app.logo_url');

        return [
            "id" => $this->id,
            "qrcode" => $onlineMenu?->slug,
            "uuid" => $this->uuid,
            "name" => $this->name,
            "branch" => [
                "id" => $this->branch_id,
                "name" => $this->relationLoaded("branch") ? $this->branch?->name : "",
            ],
            "floor" => [
                "id" => $this->floor_id,
                "name" => $this->relationLoaded("floor") ? $this->floor?->name : "",
            ],
            "zone" => [
                "id" => $this->zone_id,
                "name" => $this->relationLoaded("zone") ? $this->zone?->name : "",
            ],
            "capacity" => $this->capacity,
            "online_menu" => [
                "id" => $onlineMenu?->id,
                "slug" => $onlineMenu?->slug,
            ],
            "brand" => [
                "name" => $tenant?->name ?: ($this->branch?->name ?: config('app.name')),
                "logo_url" => $logoUrl,
                "primary_color" => data_get($tenantSettings, 'theme.primary')
                    ?? data_get($tenantSettings, 'branding.primary_color')
                    ?? data_get($tenantSettings, 'brand.primary_color'),
            ],
            "guest_count" => (int) $this->active_orders_guest_count,
            "status" => $this->status?->toTrans(),
            "shape" => $this->shape->toTrans(),
            "is_active" => $this->is_active,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
