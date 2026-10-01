<?php

namespace Modules\Expense\Services\Expense;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Expense\Models\Expense;

interface ExpenseServiceInterface
{
    public function label(): string;

    public function model(): string;

    public function getModel(): Expense;

    /**
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): Expense|Builder|EloquentCollection|array;

    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * @throws ModelNotFoundException
     */
    public function show(int $id): Expense;

    public function store(array $data): Expense;

    /**
     * @throws ModelNotFoundException
     */
    public function update(int $id, array $data): Expense;

    public function destroy(int|array|string $ids): bool;

    public function getFormMeta(): array;
}
