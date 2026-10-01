<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Pos\Enums\PosSessionStatus;
use Modules\SeatingPlan\Enums\TableMergeType;
use Modules\SeatingPlan\Enums\TableStatus;

/**
 * @property-read int|null $floor_id
 * @property-read int|null $zone_id
 */
class TableMergeRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $user = auth()->user();
        $branchId = $user->assignedToBranch() ? $user->branch_id : $this->input('branch_id');

        return [
            'branch_id' => 'bail|required|integer|exists:branches,id,deleted_at,NULL,is_active,1',
            'table_ids' => 'required|array',
            'table_ids.*' => [
                'bail',
                'required',
                'numeric',
                Rule::exists('tables', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', 1)
                    ->whereNotIn('status', [
                        TableStatus::Merged->value,
                        TableStatus::Cleaning->value,
                    ])
                    ->whereNull('current_merge_id')
                    ->whereNot('id', $this->route('id'))
                    ->where('branch_id', $branchId),
            ],
            'type' => ['required', Rule::enum(TableMergeType::class)],
            'register_id' => [
                'bail',
                'required_if:type,'.TableMergeType::Order->value,
                'nullable',
                'integer',
                Rule::exists('pos_registers', 'id')
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('branch_id', $this->input('branch_id')),
            ],
            'session_id' => [
                'bail',
                'required_if:type,'.TableMergeType::Order->value,
                'nullable',
                'integer',
                Rule::exists('pos_sessions', 'id')
                    ->whereNull('deleted_at')
                    ->where('status', PosSessionStatus::Open->value)
                    ->where('branch_id', $this->input('branch_id'))
                    ->where('pos_register_id', $this->input('register_id')),
            ],
        ];
    }

    /** {@inheritDoc} */
    protected function availableAttributes(): string
    {
        return 'seatingplan::attributes.table_merges';
    }
}
