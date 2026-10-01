<?php

namespace Modules\Expense\Services\Expense;

use App\NexDine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Expense;
use Modules\Expense\Services\ExpenseCategory\ExpenseCategoryServiceInterface;
use Modules\Support\Money;

class ExpenseService implements ExpenseServiceInterface
{
    public function __construct(
        private readonly ExpenseCategoryServiceInterface $categoryService
    ) {
    }

    /** @inheritDoc */
    public function label(): string
    {
        return __("expense::expenses.expense");
    }

    /** @inheritDoc */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator
    {
        return $this->getModel()
            ->query()
            ->with(["category:id,name", "user:id,name"])
            ->when($this->branchId(), fn(Builder $q, $id) => $q->where('branch_id', $id))
            ->filters($filters)
            ->sortBy($sorts)
            ->latest('expense_date')
            ->paginate(NexDine::paginate())
            ->withQueryString();
    }

    /** @inheritDoc */
    public function getModel(): Expense
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function model(): string
    {
        return Expense::class;
    }

    /** @inheritDoc */
    public function show(int $id): Expense
    {
        return $this->findOrFail($id);
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Expense|Builder|EloquentCollection|array
    {
        return $this->getModel()
            ->query()
            ->with(["category:id,name", "user:id,name"])
            ->when($this->branchId(), fn(Builder $q, $id) => $q->where('branch_id', $id))
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function store(array $data): Expense
    {
        $data['branch_id'] ??= $this->branchId();
        $data['user_id'] ??= auth()->id();
        $data['currency'] ??=
            auth()->user()?->branch?->currency ?? Money::defaultCurrency();
        $data['status'] ??= 'approved';

        return $this->getModel()->query()->create($data);
    }

    /** @inheritDoc */
    public function update(int $id, array $data): Expense
    {
        $expense = $this->findOrFail($id);
        $expense->update($data);

        return $expense;
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        return $this->getModel()
            ->query()
            ->when($this->branchId(), fn(Builder $q, $id) => $q->where('branch_id', $id))
            ->whereIn("id", parseIds($ids))
            ->delete() ?: false;
    }

    /** @inheritDoc */
    public function getFormMeta(): array
    {
        return [
            "branches" => Branch::list(),
            "categories" => $this->categoryService->list(),
        ];
    }

    /**
     * The authenticated user's branch id, or null for tenant/admin-wide access.
     */
    private function branchId(): ?int
    {
        $user = auth()->user();
        return $user?->assignedToBranch() ? $user->branch_id : null;
    }
}
