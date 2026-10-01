<?php

namespace Modules\Order\Http\Requests\Api\V1;

use Arr;
use Illuminate\Validation\Rule;
use Modules\Support\InputLimit;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Requests\Request;
use Modules\Menu\Models\Menu;
use Modules\Order\Enums\OrderType;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\User\Enums\DefaultRole;

class CreateOrderRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $user = auth()->user();

        $branchId = $user->assignedToBranch() ? $user->branch_id : Branch::main()->first()?->id;

        
        $menu = Menu::getActiveMenu($branchId);

        abort_if(is_null($menu), 400, __("pos::messages.menu_is_not_active"));

        $orderTypes = $menu->branch->order_types ?: [];
        $paymentMethods = $menu->branch->payment_methods;

        if ($user->hasRole(DefaultRole::Waiter->value) && in_array(OrderType::DineIn->value, $orderTypes)) {
            $orderTypes = [OrderType::DineIn->value];
        }

        return [
            "type" => ["required", Rule::in($orderTypes)],
            "table_id" => [
                "bail",
                "required_if:type,dine_in",
                "nullable",
                "numeric",
                Rule::exists("tables", "id")
                    ->whereNull("deleted_at")
                    ->where('is_active', true)
                    ->where('branch_id', $menu->branch_id),

                function ($attribute, $value, $fail) {

                    $table = Table::with(['activeOrders'])
                        ->where('id', $value)
                        ->first();

                    if (!$table) {
                        return;
                    }

                    // 🧮 Existing guests on table
                    $existingGuests = $table->activeOrders
                        ->sum(fn ($order) => (int) ($order->guest_count ?? 1));

                    // 👤 New order guests
                    $newGuests = (int) ($this->input('guest_count') ?? 1);

                    // ❌ Capacity exceeded
                    if (($existingGuests + $newGuests) > $table->capacity) {
                        $fail(__("seatingplan::tables.capacity_exceeded"));
                    }
                }
            ],
            "payment_methods" => "nullable|array",
            "payment_methods.*" => ["required", Rule::in($paymentMethods)],
            "payments" => [
                Rule::requiredIf(fn() => count($this->input('payment_methods', [])) > 1),
                "array",
                Rule::when(
                    fn() => count($this->input('payment_methods', [])) > 1,
                    "min:2"
                ),
            ],
            "payments.*.method" => [
                "required",
                Rule::in($this->input('payment_methods', [])),
                function ($attribute, $value, $fail) {
                    $methods = Arr::pluck($this->input('payments', []), 'method');
                    if (count($methods) !== count(array_unique($methods))) {
                        $fail(__("pos::messages.invalid_payment_method"));
                    }
                }
            ],

            "payments.*.amount" => "required|numeric|min:1|max:99999999999999",
            "pos_register_id" => "bail|required|numeric|exists:pos_registers,id,deleted_at,NULL,is_active,1,branch_id,$menu->branch_id",
            'customer_id' => 'bail|required|numeric|exists:users,id,deleted_at,NULL',
            // Bounded line-item count: an unbounded array let one request carry
            // an arbitrary number of items through order creation. `required`
            // is kept explicitly — `array` is not an implicit rule, so dropping
            // it would let a missing `products` key skip validation entirely.
            "products" => ['required', ...InputLimit::lineItems()],
            "products.*.id" => "bail|required|numeric|exists:products,id,deleted_at,NULL,is_active,1,menu_id,$menu->id",
            "products.*.quantity" => ['required', ...InputLimit::quantity(min: 1)],
            "products.*.options" => "nullable|array",
            "products.*.options.*.id" => "bail|required|numeric|exists:options,id,deleted_at,NULL,is_global,0,branch_id,$menu->branch_id",
            "products.*.options.*.values" => "required|array",
            "products.*.options.*.values.*.id" => "bail|required|numeric|exists:option_values,id,branch_id,$menu->branch_id",
            "products.*.options.*.values.*.value" => "nullable|string|max:255",
            "notes" => "nullable|string|max:1000",
            "guest_count" => ['nullable', 'integer', 'min:1', 'max:' . config('validation.counts.table_capacity', 100)],
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "order::attributes.orders";
    }
}