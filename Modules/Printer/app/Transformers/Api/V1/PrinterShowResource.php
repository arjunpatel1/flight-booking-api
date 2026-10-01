<?php

namespace Modules\Printer\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Printer\Models\Printer;

/** @mixin Printer */
class PrinterShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new PrinterResource($this))->resolve(),
            "options" => $this->options,
        ];
    }
}
