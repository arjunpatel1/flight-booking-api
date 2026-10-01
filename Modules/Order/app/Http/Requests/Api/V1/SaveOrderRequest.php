<?php

namespace Modules\Order\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Requests\Request;
use Modules\Menu\Models\Menu;
use Modules\Order\Enums\OrderType;
use Modules\Order\Services\Order\OrderServiceInterface;
use Modules\Payment\Enums\RefundPaymentMethod;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\Pos\Enums\PosSubmitAction;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class SaveOrderRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $user = auth()->user();

        if ($user->assignedToBranch()) {
            $this->offsetSet('branch_id', $user->branch_id);
        }

        $branchId = $this->input('branch_id');

        /** @var Branch $branch */
        $branch = Branch::withoutGlobalActive()->find($branchId);

        $orderTypes = $this->allowedOrderTypesForUser($branch?->order_types ?: [], $user);

        $order = null;
        if ($this->method() == 'PUT') {
            $order = app(OrderServiceInterface::class)->findOrFail($this->route('orderId'));
        }

        return [
            "submit_action" => [
                "required",
                Rule::enum(PosSubmitAction::class),
                function ($attribute, $value, $fail) {
                    if ($this->isMethod('put') && $value === PosSubmitAction::HoldOrder->value) {
                        $fail(__('pos::pos_viewer.hold_order_not_allowed_for_edit'));
                    }
                },
            ],
            "auto_print_kot" => ["sometimes", "boolean"],
            "refund_payment_method" => ["nullable", Rule::in(RefundPaymentMethod::values())],
            "branch_id" => "bail|required|integer|exists:branches,id,deleted_at,NULL,is_active,1",
            "menu_id" => [
                "bail",
                "required",
                "integer",
                Rule::exists("menus", "id")
                    ->whereNull("deleted_at")
                    ->where("branch_id", $branchId)
                    ->when($this->isMethod('post'), fn ($rule) => $rule->where("is_active", true)),
                function ($attribute, $value, $fail) {
                    if (! $this->isMethod('post')) {
                        return;
                    }

                    $orderTypes = Menu::query()
                        ->withoutGlobalActive()
                        ->withOutGlobalBranchPermission()
                        ->whereKey($value)
                        ->value('order_types') ?: [];
                    $orderType = $this->input('type');

                    if (! empty($orderTypes) && ! in_array($orderType, $orderTypes, true)) {
                        $fail(__('menu::menus.menu_not_available_for_order_type'));
                    }
                },
            ],
            "type" => ["required", Rule::in($orderTypes)],
            "table_id" => [
                "bail",
                "required_if:type,dine_in",
                "nullable",
                "integer",
                Rule::exists("tables", "id")
                    ->whereNull("deleted_at")
                    ->where("is_active", true)
                    ->where("branch_id", $branchId),
                function ($attribute, $value, $fail) use ($order) {
                    $table = Table::with(['activeOrders'])
                        ->where('id', $value)
                        ->whereNull('deleted_at')
                        ->where('is_active', true)
                        ->first();

                    if (! $table) {
                        return;
                    }
                    // 🧮 Existing active guests on table
                    $existingGuests = $table->activeOrders
                        ->when(
                            $order,
                            fn ($q) => $q->where('id', '!=', $order->id) // update case safety
                        )
                        ->sum(fn ($order) => (int) ($order->guest_count ?? 1));
                    // 👤 New / current order guests
                    $newGuests = (int) ($this->input('guest_count') ?? 1);

                    // ❌ Capacity validation
                    if (($existingGuests + $newGuests) > $table->capacity) {
                        $fail(__('seatingplan::tables.capacity_exceeded'));
                    }
                }
            ],
            "register_id" => [
                "bail",
                "required",
                "integer",
                Rule::exists("pos_registers", "id")
                    ->whereNull("deleted_at")
                    ->where('is_active', true)
                    ->where('branch_id', $branchId)
            ],
            "session_id" => [
                "bail",
                "required",
                "integer",
                Rule::exists("pos_sessions", "id")
                    ->whereNull("deleted_at")
                    ->where('status', PosSessionStatus::Open->value)
                    ->where('branch_id', $branchId)
                    ->where('pos_register_id', $this->input('register_id'))
            ],
            "waiter_id" => [
                "bail",
                "nullable",
                "integer",
                Rule::exists("users", 'id')
                    ->whereNull("deleted_at")
                    ->where('branch_id', $branchId)
                    ->where('is_active', true)
            ],
            "notes" => "nullable|string|max:1000",
            "guest_count" => "nullable|integer|min:1",
            "car_plate" => "nullable|string|max:200",
            "car_description" => "nullable|string|max:200",
            "scheduled_at" => "required_if:type,pre_order|nullable|date|after_or_equal:today|date_format:Y-m-d H:i:s",
        ];

    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "order::attributes.orders";
    }

    private function allowedOrderTypesForUser(array $branchOrderTypes, User $user): array
    {
        $branchOrderTypes = array_values(array_filter($branchOrderTypes));

        if (!$user->hasRole(DefaultRole::Waiter->value)) {
            return $branchOrderTypes;
        }

        $assignedOrderTypes = array_values(array_filter($user->order_types ?: []));
        if (empty($assignedOrderTypes)) {
            return $branchOrderTypes;
        }

        return array_values(array_intersect($branchOrderTypes, $assignedOrderTypes));
    }
}
