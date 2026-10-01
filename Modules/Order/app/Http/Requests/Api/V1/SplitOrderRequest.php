<?php

namespace Modules\Order\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SplitOrderRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "items" => "required|array|min:1",
            "items.*" => "integer|distinct|exists:order_products,id",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "order::attributes.split";
    }
}
