<?php

namespace Modules\Payment\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class ComplimentaryPaymentRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "order_id" => "required",
            "reason" => "required|string|max:500",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "payment::attributes.complimentary";
    }
}
