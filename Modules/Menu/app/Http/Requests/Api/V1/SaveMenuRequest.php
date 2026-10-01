<?php

namespace Modules\Menu\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Order\Enums\OrderType;

class SaveMenuRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules([
                "name" => "required|string|max:255",
                "description" => "nullable|string|max:500",
            ]),
            ...$this->getBranchRule(),
            "order_types" => "nullable|array",
            "order_types.*" => ["required", Rule::in(OrderType::values())],
            "is_active" => "required|boolean",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "menu::attributes.menus";
    }
}
