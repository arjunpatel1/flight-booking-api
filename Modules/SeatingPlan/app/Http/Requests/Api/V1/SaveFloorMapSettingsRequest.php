<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SaveFloorMapSettingsRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "layout_width" => "required|integer|min:1000|max:2600",
            "layout_height" => "required|integer|min:700|max:1800",
            "show_grid" => "required|boolean",
            "show_guide_lines" => "required|boolean",
            "show_zone_labels" => "required|boolean",
            "show_table_labels" => "required|boolean",
            "compact_tables" => "required|boolean",
            "zone_layouts" => "nullable|array|max:200",
            "zone_layouts.*.left" => "required|numeric|min:0|max:10000",
            "zone_layouts.*.top" => "required|numeric|min:0|max:10000",
            "zone_layouts.*.width" => "required|numeric|min:280|max:10000",
            "zone_layouts.*.height" => "required|numeric|min:240|max:10000",
            "layout_elements" => "nullable|array|max:200",
            "layout_elements.*.id" => "required|string|max:80",
            "layout_elements.*.type" => "required|string|in:line,text",
            "layout_elements.*.x" => "required|numeric|min:0|max:10000",
            "layout_elements.*.y" => "required|numeric|min:0|max:10000",
            "layout_elements.*.width" => "nullable|numeric|min:20|max:5000",
            "layout_elements.*.height" => "nullable|numeric|min:0|max:1000",
            "layout_elements.*.rotation" => "nullable|numeric|min:0|max:359.99",
            "layout_elements.*.text" => "nullable|string|max:120",
            "layout_elements.*.color" => "nullable|string|max:40",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "seatingplan::attributes.floors";
    }
}
