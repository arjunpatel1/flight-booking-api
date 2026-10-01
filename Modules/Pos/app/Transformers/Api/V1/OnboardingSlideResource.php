<?php

namespace Modules\Pos\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Media\Transformers\Api\V1\MediaSimpleResource;

class OnboardingSlideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'description'  => $this->description,
            'accent_color' => $this->accent_color,
            'cta_label'    => $this->cta_label,
            'image'        => $this->image ? new MediaSimpleResource($this->image) : null,
            'sort_order'   => $this->sort_order,
            'is_active'    => $this->is_active,
            'created_at'   => dateTimeFormat($this->created_at),
            'updated_at'   => dateTimeFormat($this->updated_at),
        ];
    }
}
