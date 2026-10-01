<?php

namespace Modules\Product\Services\ProductFavorite;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Product\Models\ProductFavorite;

class ProductFavoriteService implements ProductFavoriteServiceInterface
{
    /** @inheritDoc */
    public function label(): string
    {
        return __("product::products.favorite");
    }

    /** @inheritDoc */
    public function model(): string
    {
        return ProductFavorite::class;
    }

    /** @inheritDoc */
    public function getModel(): ProductFavorite
    {
        return new ($this->model());
    }

    /** @inheritDoc */
    public function findOrFail(int $id): Builder|array|EloquentCollection|ProductFavorite
    {
        return $this->getModel()
            ->query()
            ->findOrFail($id);
    }

    /** @inheritDoc */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator
    {
        $query = $this->getModel()
            ->query()
            ->with(['user:id,name', 'branch:id,name', 'product:id,name,price']);

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (array_key_exists('branch_id', $filters)) {
            $filters['branch_id'] === null
                ? $query->whereNull('branch_id')
                : $query->where('branch_id', $filters['branch_id']);
        }

        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy('created_at', 'desc')
            ->paginate(min(max((int) ($filters['per_page'] ?? 100), 1), 200));
    }

    /** @inheritDoc */
    public function store(array $data): ProductFavorite
    {
        $favorite = $this->getModel()
            ->query()
            ->updateOrCreate(
                [
                    'user_id' => $data['user_id'],
                    'product_id' => $data['product_id'],
                    'branch_id' => $data['branch_id'] ?? null,
                ],
                $data
            );

        return $favorite->load(['user:id,name', 'branch:id,name', 'product:id,name,price']);
    }

    /** @inheritDoc */
    public function destroy(int|array|string $ids): bool
    {
        $ids = is_string($ids) ? array_filter(explode(',', $ids)) : (array) $ids;

        return $this->getModel()
            ->query()
            ->whereIn('id', $ids)
            ->delete() > 0;
    }

    /** @inheritDoc */
    public function toggle(int $userId, int $productId, ?int $branchId = null): array
    {
        $existing = $this->getModel()
            ->query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->when(
                $branchId === null,
                fn($query) => $query->whereNull('branch_id'),
                fn($query) => $query->where('branch_id', $branchId)
            )
            ->first();

        if ($existing) {
            $existing->delete();
            return [
                'is_favorited' => false,
                'favorite' => null,
            ];
        }

        return [
            'is_favorited' => true,
            'favorite' => $this->store([
                'user_id' => $userId,
                'product_id' => $productId,
                'branch_id' => $branchId,
            ]),
        ];
    }

    /** @inheritDoc */
    public function isFavorited(int $userId, int $productId, ?int $branchId = null): bool
    {
        return $this->getModel()
            ->query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->when(
                $branchId === null,
                fn($query) => $query->whereNull('branch_id'),
                fn($query) => $query->where('branch_id', $branchId)
            )
            ->exists();
    }
}
