<?php

namespace Modules\Product\Services\ProductFavorite;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Product\Models\ProductFavorite;

interface ProductFavoriteServiceInterface
{
    /**
     * Label for the resource.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Model for the resource.
     *
     * @return string
     */
    public function model(): string;

    /**
     * Get a new instance of the model.
     *
     * @return ProductFavorite
     */
    public function getModel(): ProductFavorite;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return ProductFavorite|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int $id): ProductFavorite|Builder|EloquentCollection|array;

    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return ProductFavorite
     */
    public function store(array $data): ProductFavorite;

    /**
     * Destroy resource's by given id.
     *
     * @param int|array|string $ids
     * @return bool
     * @throws ModelNotFoundException
     * @throws LogicException
     */
    public function destroy(int|array|string $ids): bool;

    /**
     * Toggle favorite status for a user and product.
     *
     * @param int $userId
     * @param int $productId
     * @param int|null $branchId
     * @return array{is_favorited: bool, favorite: ProductFavorite|null}
     */
    public function toggle(int $userId, int $productId, ?int $branchId = null): array;

    /**
     * Check if a product is favorited by a user.
     *
     * @param int $userId
     * @param int $productId
     * @param int|null $branchId
     * @return bool
     */
    public function isFavorited(int $userId, int $productId, ?int $branchId = null): bool;
}
