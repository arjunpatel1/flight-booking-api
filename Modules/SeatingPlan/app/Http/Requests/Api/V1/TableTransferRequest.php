<?php

namespace Modules\SeatingPlan\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\SeatingPlan\Enums\TableStatus;

class TableTransferRequest extends Request
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $sourceTable = $this->route('id')
            ? \Modules\SeatingPlan\Models\Table::query()->find($this->route('id'))
            : null;

        $branchId = auth()->user()?->assignedToBranch()
            ? auth()->user()->branch_id
            : $sourceTable?->branch_id;

        $targetTableRule = Rule::exists('tables', 'id')
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->whereIn('status', [TableStatus::Available->value, TableStatus::Occupied->value])
            ->whereNull('current_merge_id')
            ->whereNot('id', $this->route('id'));

        if ($branchId) {
            $targetTableRule->where('branch_id', $branchId);
        }

        return [
            'target_table_id' => [
                'bail',
                'required',
                'integer',
                $targetTableRule,
            ],
        ];
    }

    /** {@inheritDoc} */
    protected function availableAttributes(): string
    {
        return 'seatingplan::attributes.table_transfers';
    }
}
