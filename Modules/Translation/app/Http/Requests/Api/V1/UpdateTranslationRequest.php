<?php

namespace Modules\Translation\Http\Requests\Api\V1;


use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;

class UpdateTranslationRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            "locale" => ["required", Rule::in(supportedLocaleKeys())],
            "value" => "required"
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "translation::attributes.translations";
    }
}
