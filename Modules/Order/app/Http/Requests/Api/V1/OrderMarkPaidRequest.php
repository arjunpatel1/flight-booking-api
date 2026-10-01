<?php

namespace Modules\Order\Http\Requests\Api\V1;

use Arr;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Order\Services\Order\OrderServiceInterface;
use Modules\SeatingPlan\Models\TableMerge;

class OrderMarkPaidRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        if (!is_null($this->route('id'))) {
            $branch = TableMerge::with("branch")->findOrFail($this->route('id'))->branch;
        } else {
            $branch = app(OrderServiceInterface::class)
                ->findOrFail($this->route('orderId'), true)->branch;
        }

        return [
            "payment_methods" => "required|array",
            "payment_methods.*" => ["required", Rule::in($branch->payment_methods)],
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
            "pos_register_id" => "bail|required|numeric|exists:pos_registers,id,deleted_at,NULL,is_active,1,branch_id,$branch->id",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "order::attributes.mark_paid";
    }
}
