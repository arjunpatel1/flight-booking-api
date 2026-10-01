<?php

namespace Modules\User\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Models\EmployeeAttendance;

/** @mixin EmployeeAttendance */
class EmployeeAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $workedMinutes = (int) $this->worked_minutes;

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
            'shift' => [
                'id' => $this->relationLoaded('shift') ? $this->shift?->id : $this->employee_shift_id,
                'name' => $this->relationLoaded('shift') ? $this->shift?->name : '',
                'starts_at' => $this->relationLoaded('shift') ? $this->shift?->starts_at : null,
                'ends_at' => $this->relationLoaded('shift') ? $this->shift?->ends_at : null,
            ],
            'clock_in_at' => dateTimeFormat($this->clock_in_at),
            'clock_in_at_iso' => $this->clock_in_at?->toISOString(),
            'clock_out_at' => $this->clock_out_at ? dateTimeFormat($this->clock_out_at) : null,
            'clock_out_at_iso' => $this->clock_out_at?->toISOString(),
            'break_minutes' => $this->break_minutes,
            'worked_minutes' => $workedMinutes,
            'worked_duration' => sprintf('%dh %02dm', intdiv($workedMinutes, 60), $workedMinutes % 60),
            'status' => [
                'id' => $this->status,
                'name' => __("user::employee_attendances.statuses.{$this->status}"),
            ],
            'notes' => $this->notes,
            'created_at' => dateTimeFormat($this->created_at),
        ];
    }
}
