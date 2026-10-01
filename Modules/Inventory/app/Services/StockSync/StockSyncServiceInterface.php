<?php

namespace Modules\Inventory\Services\StockSync;

use Illuminate\Support\Collection;
use Modules\Order\Models\Order;
use Modules\Product\Models\Product;
use Throwable;

interface StockSyncServiceInterface
{

    /**
     * Deduct order stock
     *
     * @param Order $order
     * @return void
     * @throws Throwable
     */
    public function deductOrderStock(Order $order): void;

    /**
     * Resync order stock after an already-deducted order is edited.
     *
     * @param Order $order
     * @return void
     * @throws Throwable
     */
    public function resyncOrderStock(Order $order): void;

    /**
     * Restore order stock
     *
     * @param Order $order
     * @return void
     * @throws Throwable
     */
    public function restoreOrderStock(Order $order): void;
    
    /**
     * Resolve product bom
     *
     * @param Product $product
     * @param Collection $values
     * @param int $branchId
     * @return Collection
     */
    public function resolveProductBom(Product $product, Collection $values, int $branchId): Collection;

}
