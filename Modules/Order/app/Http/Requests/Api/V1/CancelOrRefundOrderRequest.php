<?php

namespace Modules\Order\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Order\Services\Order\OrderServiceInterface;
use Modules\Payment\Enums\RefundPaymentMethod;

class CancelOrRefundOrderRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $order = app(OrderServiceInterface::class)
            ->findOrFail($this->route('orderId'), true);

        $rules = [
            "reason_id" => "bail|required|exists:reasons,id,deleted_at,NULL,is_active,1",
            "register_id" => [
                "bail",
                "required",
                "integer",
                Rule::exists("pos_registers", "id")
                    ->whereNull("deleted_at")
                    ->where('is_active', true)
                    ->where('branch_id', $order->branch->id)
            ],
            "note" => "nullable|string|max:1000",
            "manager_approval_token" => "nullable|string|max:120",
        ];

        if ($order->hasRefundAmount()) {
            $rules["refund_payment_method"] = [
                "required",
                Rule::in(
                    array_filter(
                        RefundPaymentMethod::values(),
                        fn($value) => in_array($value, $order->branch->payment_methods ?: [])
                    )
                )
            ];
        }

        return $rules;
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "order::attributes.cancel_or_refund";
    }
}
