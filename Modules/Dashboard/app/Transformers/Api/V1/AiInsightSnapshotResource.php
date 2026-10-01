<?php

namespace Modules\Dashboard\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiInsightSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'window_days' => $this->window_days,
            'currency' => $this->currency,
            'summary' => $this->summary,
            'recommendations' => $this->recommendations,
            'generated_at' => dateTimeFormat($this->generated_at),
            'generated_by' => $this->relationLoaded('generator') ? $this->generator?->name : null,
        ];
    }
}
