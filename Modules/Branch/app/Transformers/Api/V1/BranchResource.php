<?php

namespace Modules\Branch\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Branch\Models\Branch;

/** @mixin Branch */
class BranchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,
            "tenant_id" => $this->tenant_id,
            "name" => $this->name,
            "registration_number" => $this->registration_number,
            "phone" => $this->phone,
            "email" => $this->email,
            "latitude" => $this->latitude,
            "longitude" => $this->longitude,
            "country_name" => $this->getCountryName(),
            "country_code" => $this->country_code,
            "timezone" => $this->timezone,
            "currency" => $this->currency,
            "is_active" => $this->is_active,
            "is_main" => $this->is_main,
            "order_types" => $this->order_types,
            "payment_methods" => $this->payment_methods,
            "quick_pay_amounts" => $this->quick_pay_amounts,
            "hide_waiter_selection" => $this->hide_waiter_selection,
            "appearance_settings" => $this->appearance_settings,
            "cash_difference_threshold" => $this->cash_difference_threshold,
            "legal_name" => $this->legal_name,
            "vat_tin" => $this->vat_tin,
            "address_line1" => $this->address_line1,
            "address_line2" => $this->address_line2,
            "city" => $this->city,
            "state" => $this->state,
            "postal_code" => $this->postal_code,
            "created_at" => dateTimeFormat($this->created_at),
            "updated_at" => dateTimeFormat($this->updated_at),
        ];
    }
}
