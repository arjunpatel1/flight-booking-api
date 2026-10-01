<?php

namespace Modules\Order\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Order\Enums\ReasonType;

class SaveReasonRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules(["name" => "required|string|max:255"]),
            "type" => ["required", Rule::enum(ReasonType::class)],
            "is_active" => "required|boolean",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "order::attributes.reasons";
    }
}
