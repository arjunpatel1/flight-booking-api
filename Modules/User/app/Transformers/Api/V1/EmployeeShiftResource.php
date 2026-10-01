<?php

namespace Modules\User\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\EmployeeShift;

/** @mixin EmployeeShift */
class EmployeeShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch' => [
                'id' => $this->branch_id,
                'name' => $this->relationLoaded('branch') ? $this->branch?->name : '',
            ],
            'user' => [
                'id' => $this->user_id,
                'name' => $this->relationLoaded('user') ? $this->user?->name : '',
            ],
            'name' => $this->name,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'break_minutes' => $this->break_minutes,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => dateTimeFormat($this->created_at),
            'updated_at' => dateTimeFormat($this->updated_at),
        ];
    }
}
