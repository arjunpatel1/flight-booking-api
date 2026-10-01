<?php

namespace Modules\Pos\Http\Requests\Api\V1;

use Modules\Core\Http\Requests\Request;

/**
 * @property int|null $branch_id
 */
class OpenSessionRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            ...$this->getBranchRule(),
            "pos_register_id" => "bail|required|numeric|exists:pos_registers,id,deleted_at,NULL,is_active,1,branch_id," . $this->branch_id,
            'opening_float' => 'nullable|numeric|min:0|max:99999999999999',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "pos::attributes.pos_sessions";
    }
}
