<?php

namespace Modules\ActivityLog\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ActivityLog\Models\ActivityLog;

/** @mixin ActivityLog */
class ActivityLogShowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $properties = $this->redact((array) $this->properties);
        unset($properties['trans_params']);

        return [
            ...(new ActivityLogResource($this))->toArray($request),
            "subject_id" => $this->subject_id,
            "subject_type" => $this->subject_type,
            'batch_uuid' => $this->batch_uuid,
            "properties" => $properties
        ];
    }

    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (preg_match('/password|passwd|secret|token|auth.?key|signature|cookie|authorization/i', (string) $key)) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
