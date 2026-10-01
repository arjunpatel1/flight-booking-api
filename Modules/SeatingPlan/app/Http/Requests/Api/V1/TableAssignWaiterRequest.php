<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;

class TableAssignWaiterRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {

        return [
            "waiter_id" => [
                'bail',
                'nullable',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->where('is_active', 1)
                        ->whereNull('deleted_at')
                        ->whereNotNull('branch_id');
                }),
            ]
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "seatingplan::attributes.tables";
    }
}
