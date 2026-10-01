<?php

namespace Modules\Product\Services\ProductOption;

use Modules\Product\Models\Product;

interface ProductOptionServiceInterface
{
    /**
     * Sync Product Options.
     *
     * @param Product $product
     * @param array $options
     * @return void
     */
    public function syncOptions(Product $product, array $options): void;
}
