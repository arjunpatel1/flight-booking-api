<?php

namespace Modules\Currency\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

class SaveCurrencyRateRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'rate' => 'required|numeric|max:9999',
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "currency::attributes.currency_rates";
    }
}
