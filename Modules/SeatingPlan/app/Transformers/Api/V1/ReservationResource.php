<?php

namespace Modules\SeatingPlan\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableReservation;
use Modules\SeatingPlan\Support\ReservationActionPolicy;

/** @mixin TableReservation */
class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'booking_type' => $this->booking_type?->value,
            'booking_type_label' => $this->booking_type?->trans(),
            'branch_id' => $this->branch_id,
            'branch_name' => $this->relationLoaded('branch') ? $this->branch?->name : null,
            'table_id' => $this->table_id,
            'table_ids' => $this->tableIds(),
            'table_name' => $this->relationLoaded('table') ? $this->table?->name : null,
            'table_names' => $this->tableNames(),
            'floor_id' => $this->relationLoaded('table') ? $this->table?->floor_id : null,
            'zone_id' => $this->relationLoaded('table') ? $this->table?->zone_id : null,
            'hall_name' => $this->hall_name,
            'event_title' => $this->event_title,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_email' => $this->customer_email,
            'guest_count' => $this->guest_count,
            'reservation_date' => $this->reservation_date?->format('Y-m-d'),
            'reservation_time' => substr((string) $this->reservation_time, 0, 5),
            'duration_minutes' => $this->duration_minutes,
            'deposit_amount' => $this->deposit_amount,
            'status' => $this->status?->value,
            'status_label' => $this->status?->trans(),
            'status_color' => $this->status?->color(),
            'action_policy' => ReservationActionPolicy::make($this->resource, $request->user()),
            'special_requests' => $this->special_requests,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => dateTimeFormat($this->created_at),
            'updated_at' => dateTimeFormat($this->updated_at),
        ];
    }

    private function tableIds(): array
    {
        $ids = $this->table_ids ?: [$this->table_id];

        return collect($ids)->filter()->map(fn($id) => (int) $id)->values()->all();
    }

    private function tableNames(): array
    {
        $ids = $this->tableIds();

        if (empty($ids)) {
            return [];
        }

        if (count($ids) === 1 && $this->relationLoaded('table') && $this->table) {
            return [$this->table->name];
        }

        return Table::query()
            ->whereIn('id', $ids)
            ->orderByRaw('FIELD(id, ' . implode(',', $ids) . ')')
            ->pluck('name')
            ->all();
    }
}
