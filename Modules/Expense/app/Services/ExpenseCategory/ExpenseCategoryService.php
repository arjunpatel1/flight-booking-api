<?php

namespace Modules\Expense\Services\ExpenseCategory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Expense\Models\ExpenseCategory;

class ExpenseCategoryService implements ExpenseCategoryServiceInterface
{
    /** @inheritDoc */
    public function list(): Collection
    {
        $query = ExpenseCategory::query()->active();
        $this->applyScope($query);

        return $query
            ->orderBy('display_order')
            ->get(['id', 'name'])
            ->map(fn(ExpenseCategory $c) => ['id' => $c->id, 'name' => $c->name])
            ->values();
    }

    /**
     * Restrict categories to those the user may see. ExpenseCategory does not use
     * HasBranch (it allows global, null-branch categories), so tenant isolation is
     * applied here: branch users see their branch + globals; tenant users see their
     * tenant's branches + globals; super-admins see everything.
     */
    private function applyScope(Builder $query): void
    {
        $user = auth()->user();
        if (! $user || $user->isSuperAdmin()) {
            return;
        }

        if ($user->assignedToBranch()) {
            $query->where(fn($w) => $w->where('branch_id', $user->branch_id)->orWhereNull('branch_id'));
            return;
        }

        if ($user->assignedToTenant()) {
            $query->where(fn($w) => $w
                ->whereIn('branch_id', fn($sub) => $sub->select('id')->from('branches')->where('tenant_id', $user->tenant_id))
                ->orWhereNull('branch_id'));
        }
    }
}
