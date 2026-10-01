<?php

namespace Modules\Import\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'original_filename' => $this->original_filename,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'success_rows' => $this->success_rows,
            'failed_rows' => $this->failed_rows,
            'errors' => $this->errors,
            'created_at' => dateTimeFormat($this->created_at),
            'started_at' => dateTimeFormat($this->started_at),
            'finished_at' => dateTimeFormat($this->finished_at),
        ];
    }
}
