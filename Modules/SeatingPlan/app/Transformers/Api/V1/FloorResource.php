<?php

namespace Modules\SeatingPlan\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SeatingPlan\Models\Floor;

/** @mixin Floor */
class FloorResource extends JsonResource
{
    private function selectedAttribute(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->resource->getAttributes())
            ? $this->{$key}
            : $default;
    }

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
            "layout_width" => $this->selectedAttribute('layout_width'),
            "layout_height" => $this->selectedAttribute('layout_height'),
            "show_grid" => $this->selectedAttribute('show_grid'),
            "show_guide_lines" => $this->selectedAttribute('show_guide_lines'),
            "show_zone_labels" => $this->selectedAttribute('show_zone_labels'),
            "show_table_labels" => $this->selectedAttribute('show_table_labels'),
            "compact_tables" => $this->selectedAttribute('compact_tables'),
            "zone_layouts" => $this->selectedAttribute('zone_layouts', []) ?: [],
            "layout_elements" => $this->selectedAttribute('layout_elements', []) ?: [],
            "planner_snapshot" => $this->selectedAttribute('planner_snapshot') ?: null,
            "is_active" => $this->is_active,
            "updated_at" => dateTimeFormat($this->updated_at),
            "created_at" => dateTimeFormat($this->created_at),
        ];
    }
}
