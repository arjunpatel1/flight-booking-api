<?php

namespace Modules\Aggregator\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Core\Http\Requests\Request;

class SaveAggregatorIntegrationRequest extends Request
{
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'provider' => [
                'required',
                Rule::enum(AggregatorProvider::class),
                Rule::unique('aggregator_integrations', 'provider')
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],
            'name' => 'required|string|max:255',
            'base_url' => 'nullable|url|max:500',
            'credentials' => 'nullable|array',
            'webhook_secret' => 'nullable|string|max:255',
            'settings' => 'nullable|array',
            'is_active' => 'required|boolean',
        ];
    }

    protected function availableAttributes(): string
    {
        return 'aggregator::attributes.integrations';
    }
}
