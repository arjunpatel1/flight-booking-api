<?php

namespace Modules\Aggregator\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Aggregator\Models\AggregatorIntegration;
use Modules\Aggregator\Services\Providers\AggregatorProviderFactory;

/** @mixin AggregatorIntegration */
class AggregatorIntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider?->toTrans(),
            'provider_id' => $this->provider?->value,
            'name' => $this->name,
            'base_url' => $this->base_url,
            'credentials' => $this->credentials,
            'webhook_secret' => $this->webhook_secret,
            'settings' => $this->settings,
            'capabilities' => app(AggregatorProviderFactory::class)->make($this->resource)->capabilities(),
            'is_active' => $this->is_active,
            'updated_at' => dateTimeFormat($this->updated_at),
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
