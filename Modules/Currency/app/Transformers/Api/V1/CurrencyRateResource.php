<?php

namespace Modules\Currency\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Currency\Currency;
use Modules\Currency\Models\CurrencyRate;

/** @mixin CurrencyRate */
class CurrencyRateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "currency_name" => Currency::name($this->currency),
            "currency" => $this->currency,
            "rate" => $this->rate,
            "updated_at" => dateTimeFormat($this->updated_at),
        ];
    }
}
