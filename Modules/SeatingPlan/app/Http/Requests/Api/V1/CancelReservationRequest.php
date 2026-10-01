<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class CancelReservationRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'reason' => 'nullable|string|max:1000',
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return 'seatingplan::attributes.reservations';
    }
}
