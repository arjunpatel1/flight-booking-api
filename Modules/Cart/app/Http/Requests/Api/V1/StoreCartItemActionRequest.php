<?php

namespace Modules\Cart\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Order\Enums\OrderProductAction;

class StoreCartItemActionRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "action" => ['required', Rule::enum(OrderProductAction::class)],
            "qty" => "required|numeric|min:1|max:99999",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "cart::attributes.items";
    }
}
