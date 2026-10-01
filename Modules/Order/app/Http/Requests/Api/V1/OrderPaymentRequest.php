<?php

namespace Modules\Order\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Currency\Currency;
use Modules\Order\Services\Order\OrderServiceInterface;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Enums\PaymentMode;
use Modules\Pos\Enums\PosSessionStatus;

class OrderPaymentRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {

        $branch = app(OrderServiceInterface::class)
            ->findOrFail($this->route('orderId'), true)
            ->branch;

        $rules = [
            "payments" => 'required|array',
            "payments.*.method" => ["required", Rule::in($branch->payment_methods ?: [])],
            "payments.*.amount" => "required|numeric|min:0.0001|max:99999999999999",
            "payments.*.transaction_id" => "nullable|string|max:255",
            "payment_slip" => "nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120",
            "payments.*.gateway" => ["nullable", Rule::in(['manual', 'pinelabs', 'razorpay'])],
            "payments.*.gateway_data" => "nullable|array",
            "payments.*.gateway_data.terminal_id" => "nullable|string|max:120",
            "payments.*.gateway_data.payment_id" => "nullable|string|max:120",
            "payments.*.gateway_data.gateway_payment_id" => "nullable|string|max:120",
            "payments.*.gateway_data.razorpay_payment_id" => "nullable|string|max:120",
            "payments.*.gateway_data.razorpay_order_id" => "nullable|string|max:120",
            "payments.*.gateway_data.razorpay_signature" => "nullable|string|max:255",
            "payment_mode" => ["required", Rule::enum(PaymentMode::class)],
            "with_print" => "required|boolean",
            "tip_amount" => "nullable|numeric|min:0|max:99999999999999",
            "register_id" => [
                "bail",
                "required",
                "integer",
                Rule::exists("pos_registers", "id")
                    ->whereNull("deleted_at")
                    ->where('is_active', true)
                    ->where('branch_id', $branch->id)
            ],
            "session_id" => [
                "bail",
                "required",
                "integer",
                Rule::exists("pos_sessions", "id")
                    ->whereNull("deleted_at")
                    ->where('status', PosSessionStatus::Open->value)
                    ->where('branch_id', $branch->id)
                    ->where('pos_register_id', $this->input('register_id'))
            ],
        ];

        $totalCashPayment = 0;
        foreach ($this->input('payments', []) as $payment) {
            if (isset($payment['method']) && $payment['method'] === PaymentMethod::Cash->value) {
                $totalCashPayment += $payment['amount'];
            }
        }

        if ($totalCashPayment > 0) {
            $tipAmount = max(0, (float) $this->input('tip_amount', 0));
            $minimumChange = max(
                0,
                (float) $this->input('customer_given_amount', Currency::subunit($branch->currency))
                    - $totalCashPayment
                    - $tipAmount
            );
            $rules["customer_given_amount"] = "required|numeric|min:0.0001|max:99999999999999";
            $rules["change_return"] = "required|numeric|min:" . round($minimumChange, 3) . "|max:99999999999999";
        }

        return $rules;
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "order::attributes.payments";
    }
}
