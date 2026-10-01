<?php

namespace Modules\Media\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Media\Models\Media;

/** @mixin Media */
class MediaSimpleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "name" => $this->name,
            "download_name" => $this->getDownloadName(),
            "extension" => $this->extension,
            "mime" => $this->mime_type,
            "type" => strtok($this->mime_type, '/'),
            "download_url" => $this->download_url,
            "url" => $this->url,
            "preview_image_url" => $this->preview_image_url,
        ];
    }
}
