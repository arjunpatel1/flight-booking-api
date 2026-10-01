<?php

namespace Modules\Pos\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Pos\Enums\PosCashDirection;
use Modules\Pos\Enums\PosCashReason;

/**
 * @property string|null $direction
 */
class SavePosCashMovementRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $reasons = [];
        $direction = PosCashDirection::tryFrom($this->direction);
        if (!is_null($direction)) {
            $reasons = array_map(
                fn(PosCashReason $reason) => $reason->value,
                PosCashReason::getForManageCashMovement($direction)
            );
        }

        return [
            "direction" => [
                "required",
                Rule::in([
                    PosCashDirection::In->value,
                    PosCashDirection::Out->value,
                ])
            ],
            "reason" => ["required", Rule::in($reasons)],
            "pos_register_id" => "bail|required|numeric|exists:pos_registers,id,deleted_at,NULL,is_active,1",
            'amount' => 'required|numeric|min:1|max:99999999999999',
            'notes' => 'nullable|string|max:1000',
            "reference" => "nullable|string|max:100",
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "pos::attributes.pos_cash_movements";
    }
}
