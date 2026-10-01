<?php

namespace Modules\Order\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Core\Http\Requests\Request;
use Modules\Order\Enums\OrderType;

class PublicQrOrderRequest extends Request
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('menu_reference')) {
            return;
        }

        $menu = PublicTenantGuard::menu($this, (string) $this->input('menu_reference'));
        $this->merge([
            'menu_id' => $menu->id,
            'branch_id' => $menu->branch_id,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $branchId = $this->input('branch_id');
        if (filled($branchId)) {
            PublicTenantGuard::branch($this, $branchId);
        }

        return [
            'menu_reference' => ['required_without_all:branch_id,menu_id', 'nullable', 'uuid'],
            'branch_id' => 'bail|required|integer',
            'menu_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('menus', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('branch_id', $branchId),
            ],
            'table_id' => ['prohibited'],
            'table_token' => [
                'bail',
                'nullable',
                'uuid',
            ],
            'table_qr_payload' => ['bail', 'nullable', 'string', 'max:8192'],
            // Direct online-menu orders use a human-visible table label. It is
            // resolved server-side within the validated branch, never treated
            // as a public table primary key.
            'table_number' => ['bail', 'nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in([
                OrderType::DineIn->value,
                OrderType::Takeaway->value,
                OrderType::Pickup->value,
                OrderType::Delivery->value,
            ])],
            'room_number' => 'nullable|string|max:100',
            'customer_name' => 'nullable|string|max:255',
            'customer_mobile' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:1000',
            'guest_count' => 'nullable|integer|min:1|max:999',
            'payment_method' => ['nullable', Rule::in(['cash', 'cash_on_delivery', 'pay_at_counter', 'upi', 'razorpay', 'wallet'])],
            'expected_payable_total' => ['nullable', 'numeric', 'min:0'],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before_or_equal:'.now()->addDays(7)->toDateTimeString()],
            'delivery_address' => ['nullable', 'array', 'required_if:type,'.OrderType::Delivery->value],
            'delivery_address.label' => ['nullable', 'string', 'max:60'],
            'delivery_address.id' => ['nullable', 'uuid'],
            'delivery_address.recipient_name' => ['required_if:type,'.OrderType::Delivery->value, 'string', 'max:120'],
            'delivery_address.phone' => ['required_if:type,'.OrderType::Delivery->value, 'string', 'regex:/^[0-9+() -]{7,20}$/'],
            'delivery_address.address_line1' => ['required_if:type,'.OrderType::Delivery->value, 'string', 'max:255'],
            'delivery_address.address_line2' => ['nullable', 'string', 'max:255'],
            'delivery_address.city' => ['required_if:type,'.OrderType::Delivery->value, 'string', 'max:120'],
            'delivery_address.postal_code' => ['required_if:type,'.OrderType::Delivery->value, 'string', 'max:20'],
            'delivery_address.landmark' => ['nullable', 'string', 'max:160'],
            'delivery_address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $hasWebToken = $this->filled('table_token');
            $hasSignedPayload = $this->filled('table_qr_payload');

            if ($hasWebToken && $hasSignedPayload) {
                $validator->errors()->add('table_token', 'Provide only one table QR credential.');
            }

            if ($this->input('type') === OrderType::DineIn->value && ! $hasWebToken && ! $hasSignedPayload && ! $this->filled('table_number')) {
                $validator->errors()->add('table_number', 'Enter a valid table number for a direct dine-in order.');
            }
        });
    }

    /**
     * Get the available attributes for the request.
     */
    protected function availableAttributes(): string
    {
        return 'pos::qr_order.attributes';
    }
}
