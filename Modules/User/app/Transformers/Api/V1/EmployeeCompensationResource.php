<?php

namespace Modules\User\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeCompensationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch' => [
                'id' => $this->branch_id,
                'name' => $this->relationLoaded('branch') ? $this->branch?->name : '',
            ],
            'branch_id' => $this->branch_id,
            'user' => [
                'id' => $this->user_id,
                'name' => $this->relationLoaded('user') ? $this->user?->name : '',
            ],
            'user_id' => $this->user_id,
            'pay_type' => [
                'id' => $this->pay_type,
                'name' => __("user::employee_compensations.pay_types.{$this->pay_type}"),
            ],
            'pay_type_id' => $this->pay_type,
            'base_rate' => $this->base_rate,
            'overtime_rate' => $this->overtime_rate,
            'standard_daily_minutes' => $this->standard_daily_minutes,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
