<?php

namespace Modules\Option\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Option\Enums\OptionType;
use Modules\Support\Enums\PriceType;

class SaveOptionRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules([
                "name" => 'required|string|max:255',
                'values.*.label' => 'required_if:type,select,checkbox,radio,multiple_select|nullable',
            ]),
            ...$this->getBranchRule(),
            'type' => ['required', Rule::enum(OptionType::class)],
            'is_required' => 'required|boolean',
            'values.*.id' => 'nullable|numeric',
            'values.*.price' => 'nullable|numeric|min:0|max:99999999999999',
            'values.*.price_type' => ['required', Rule::enum(PriceType::class)],
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "option::attributes.options";
    }
}
