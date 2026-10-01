<?php

namespace Modules\Branch\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Currency\Currency;
use Modules\Order\Enums\OrderType;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Support\Country;
use Modules\Support\TimeZone;


class SaveBranchRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getTranslationRules(["name" => "required|string|max:255"]),
            "tenant_id" => "nullable|exists:tenants,id",
            "legal_name" => "required|string|max:250",
            "vat_tin" => "required|string|max:100",
            "registration_number" => "required|string|max:100",
            "address_line1" => "required|string|max:255",
            "address_line2" => "nullable|string|max:255",
            "city" => "nullable|string|max:150",
            "state" => "nullable|string|max:150",
            "postal_code" => "nullable|string|max:50",
            "phone" => "required|string|max:20",
            "email" => "required|email:rfc|max:50",
            "country_code" => ["required", Rule::in(Country::codes())],
            "timezone" => ["required", Rule::in(TimeZone::keys())],
            "currency" => ["required", Rule::in(Currency::codes())],
            "latitude" => "nullable|required_with:delivery_radius_km|numeric|between:-90,90",
            "longitude" => "nullable|required_with:delivery_radius_km|numeric|between:-180,180",
            "delivery_radius_km" => "nullable|numeric|min:0.1|max:500",
            "delivery_minimum_order" => "nullable|numeric|min:0|max:99999999",
            "cuisines" => "nullable|array|max:12",
            "cuisines.*" => "required|string|max:60|distinct",
            "delivery_eta_minutes" => "nullable|integer|min:1|max:600",
            "price_for_two" => "nullable|numeric|min:0|max:99999999",
            "is_accepting_orders" => "nullable|boolean",
            // Weekday keys, each holding a list of {open, close} 24h windows.
            "opening_hours" => ["nullable", "array", function ($attribute, $value, $fail) {
                $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
                foreach (array_keys((array) $value) as $day) {
                    if (! in_array(strtolower((string) $day), $days, true)) {
                        $fail("The {$attribute} field contains an unknown weekday '{$day}'.");
                    }
                }
            }],
            "opening_hours.*" => "array|max:4",
            "opening_hours.*.*.open" => ["required", "date_format:H:i"],
            "opening_hours.*.*.close" => ["required", "date_format:H:i"],
            "is_active" => "required|boolean",
            "cash_difference_threshold" => "required|numeric|min:0|max:999999",
            'order_types' => "required|array|min:1",
            "order_types.*" => ["required", "distinct", Rule::in(OrderType::values())],
            'payment_methods' => "required|array|min:1",
            "payment_methods.*" => ["required", "distinct", Rule::in(PaymentMethod::values())],
            "quick_pay_amounts" => "nullable|array|max:12",
            "quick_pay_amounts.*" => "numeric|min:0|max:999999",
            "hide_waiter_selection" => "nullable|boolean",
            "appearance_settings" => "nullable|array",
            "appearance_settings.primary_color" => "nullable|string|max:20",
            "appearance_settings.secondary_color" => "nullable|string|max:20",
            "appearance_settings.header_color" => "nullable|string|max:20",
            "appearance_settings.button_color" => "nullable|string|max:20",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "branch::attributes.branches";
    }
}
