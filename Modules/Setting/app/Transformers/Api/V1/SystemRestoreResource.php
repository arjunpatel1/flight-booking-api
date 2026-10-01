<?php

namespace Modules\Setting\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Setting\Models\SystemRestore;

/** @mixin SystemRestore */
class SystemRestoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->reason,
            'meta' => $this->meta,
            'error_message' => $this->error_message,
            'backup' => $this->whenLoaded('backup', fn() => SystemBackupResource::make($this->backup)),
            'safety_backup' => $this->whenLoaded(
                'safetyBackup',
                fn() => $this->safetyBackup ? SystemBackupResource::make($this->safetyBackup) : null
            ),
            'started_at' => dateTimeFormat($this->started_at),
            'finished_at' => dateTimeFormat($this->finished_at),
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
