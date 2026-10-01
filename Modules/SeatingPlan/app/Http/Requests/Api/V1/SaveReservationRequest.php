<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\SeatingPlan\Enums\ReservationType;

class SaveReservationRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $bookingType = $this->input('booking_type', ReservationType::Table->value);

        return [
            'booking_type' => ['nullable', Rule::enum(ReservationType::class)],
            'branch_id' => [
                Rule::requiredIf($bookingType !== ReservationType::Table->value),
                'nullable',
                'integer',
                'exists:branches,id',
            ],
            'table_id' => [
                Rule::requiredIf($bookingType === ReservationType::Table->value && empty($this->input('table_ids'))),
                'nullable',
                'integer',
                'exists:tables,id,deleted_at,NULL',
            ],
            'table_ids' => [
                Rule::requiredIf($bookingType === ReservationType::Table->value && empty($this->input('table_id'))),
                'nullable',
                'array',
                'min:1',
                'max:20',
            ],
            'table_ids.*' => [
                'integer',
                'distinct',
                'exists:tables,id,deleted_at,NULL',
            ],
            'hall_name' => [Rule::requiredIf($bookingType === ReservationType::Hall->value), 'nullable', 'string', 'max:255'],
            'event_title' => [Rule::requiredIf($bookingType === ReservationType::Event->value), 'nullable', 'string', 'max:255'],
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_email' => 'nullable|email|max:255',
            'guest_count' => 'required|integer|min:1|max:5000',
            'reservation_date' => 'required|date',
            'reservation_time' => 'required|date_format:H:i',
            'duration_minutes' => 'nullable|integer|min:15|max:1440',
            'deposit_amount' => 'nullable|numeric|min:0|max:99999999',
            'special_requests' => 'nullable|string|max:2000',
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return 'seatingplan::attributes.reservations';
    }
}
