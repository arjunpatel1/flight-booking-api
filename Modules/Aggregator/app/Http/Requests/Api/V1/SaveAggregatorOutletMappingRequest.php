<?php

namespace Modules\Aggregator\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Aggregator\Models\AggregatorOutletMapping;
use Modules\Core\Http\Requests\Request;

class SaveAggregatorOutletMappingRequest extends Request
{
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'aggregator_integration_id' => 'required|integer|exists:aggregator_integrations,id',
            'branch_id' => [
                'required',
                'integer',
                'exists:branches,id',
                Rule::unique((new AggregatorOutletMapping())->getTable())
                    ->where('aggregator_integration_id', $this->aggregator_integration_id)
                    ->ignore($id),
            ],
            'external_outlet_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique((new AggregatorOutletMapping())->getTable())
                    ->where('aggregator_integration_id', $this->aggregator_integration_id)
                    ->ignore($id),
            ],
            'external_outlet_name' => 'nullable|string|max:255',
            'meta' => 'nullable|array',
            'is_active' => 'required|boolean',
        ];
    }

    protected function availableAttributes(): string
    {
        return 'aggregator::attributes.outlet_mappings';
    }
}
