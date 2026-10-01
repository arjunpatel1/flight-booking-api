<?php

namespace Modules\Pricing\Services\PriceType;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Pricing\Models\PriceType;

interface PriceTypeServiceInterface
{
    public function label(): string;

    public function get(array $filters = [], ?array $sorts = []): LengthAwarePaginator;

    public function getModel(): PriceType;

    public function model(): string;

    public function show(int $id): PriceType;

    public function findOrFail(int $id): Builder|array|EloquentCollection|PriceType;

    public function store(array $data): PriceType;

    public function update(int $id, array $data): PriceType;

    public function toggleStatus(int $id): PriceType;

    public function destroy(int|array|string $ids): bool;

    public function getStructureFilters(): array;

    public function getFormMeta(): array;
}
