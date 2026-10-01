<?php

namespace Modules\Invoice\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoice\Models\InvoiceParty;
use Modules\Support\Country;

/** @mixin InvoiceParty */
class InvoicePartyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "type" => $this->type->toTrans(),
            "legal_name" => $this->legal_name,
            "vat_tin" => $this->vat_tin,
            "cr_number" => $this->cr_number,
            "address_line1" => $this->address_line1,
            "address_line2" => $this->address_line2,
            "city" => $this->city,
            "state" => $this->state,
            "country" => !is_null($this->country_code)
                ? [
                    "code" => $this->country_code,
                    "name" => Country::name($this->country_code)
                ]
                : null,
            "postal_code" => $this->postal_code,
            "phone" => $this->phone,
            "email" => $this->email,
        ];
    }
}
