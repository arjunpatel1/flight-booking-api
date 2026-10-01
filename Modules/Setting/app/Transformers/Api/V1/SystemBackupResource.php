<?php

namespace Modules\Setting\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Setting\Models\SystemBackup;

/** @mixin SystemBackup */
class SystemBackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'disk' => $this->disk,
            'path' => $this->path,
            'size' => $this->size,
            'size_label' => $this->size ? number_format($this->size / 1024 / 1024, 2) . ' MB' : null,
            'meta' => $this->meta,
            'error_message' => $this->error_message,
            'started_at' => dateTimeFormat($this->started_at),
            'finished_at' => dateTimeFormat($this->finished_at),
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
