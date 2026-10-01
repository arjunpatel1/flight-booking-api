<?php

namespace Modules\Aggregator\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Aggregator\Models\AggregatorMenuMapping;
use Modules\Core\Http\Requests\Request;

class SaveAggregatorMenuMappingRequest extends Request
{
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'aggregator_integration_id' => 'required|integer|exists:aggregator_integrations,id',
            'menu_id' => [
                'required',
                'integer',
                'exists:menus,id',
                Rule::unique((new AggregatorMenuMapping())->getTable())
                    ->where('aggregator_integration_id', $this->aggregator_integration_id)
                    ->ignore($id),
            ],
            'external_menu_id' => 'nullable|string|max:255',
            'meta' => 'nullable|array',
            'sync_enabled' => 'required|boolean',
        ];
    }

    protected function availableAttributes(): string
    {
        return 'aggregator::attributes.menu_mappings';
    }
}
