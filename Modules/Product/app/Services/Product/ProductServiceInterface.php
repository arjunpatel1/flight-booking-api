<?php

namespace Modules\Product\Services\Product;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Modules\Menu\Models\Menu;
use Modules\Product\Models\Product;

interface ProductServiceInterface
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
     * @return Product
     */
    public function getModel(): Product;

    /**
     * Get specific resource
     *
     * @param int $id
     * @return Product|Builder|EloquentCollection|array;
     * @throws ModelNotFoundException
     */
    public function findOrFail(int|string $id): Product|Builder|EloquentCollection|array;

    /**
     * Display a listing of the resource.
     *
     * @param array $filters
     * @param array $sorts
     * @return LengthAwarePaginator
     */
    public function get(array $filters = [], array $sorts = []): LengthAwarePaginator;

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @param int|null $menuId
     * @return Product
     * @throws ModelNotFoundException
     */
    public function show(int|string $id, ?int $menuId = null): Product;

    /**
     * Store a newly created resource in storage.
     *
     * @param array $data
     * @return Product
     */
    public function store(array $data): Product;

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $data
     * @return Product
     * @throws ModelNotFoundException
     */
    public function update(int|string $id, array $data): Product;

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
     * Get structure filters for frontend
     *
     * @return array
     */
    public function getStructureFilters(): array;

    /**
     * Get form meta
     *
     * @param Menu|null $menu
     * @return array
     */
    public function getFormMeta(?Menu $menu = null): array;

    public function merchandisingRecommendations(?Menu $menu = null): array;

    public function applyMerchandisingRecommendations(array $data, ?Menu $menu = null): array;
}
