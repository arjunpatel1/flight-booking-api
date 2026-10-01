<?php

namespace Modules\Expense\Services\ExpenseCategory;

use Illuminate\Support\Collection;

interface ExpenseCategoryServiceInterface
{
    /**
     * Lightweight active category list ({id, name}) for form dropdowns.
     */
    public function list(): Collection;
}
