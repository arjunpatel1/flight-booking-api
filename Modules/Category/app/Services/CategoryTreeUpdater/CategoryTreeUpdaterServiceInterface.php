<?php

namespace Modules\Category\Services\CategoryTreeUpdater;

use Throwable;

interface CategoryTreeUpdaterServiceInterface
{
    /**
     * Update category tree order.
     *
     * @param int $menuId
     * @param array $tree
     *
     * @return void
     * @throws Throwable
     */
    public function update(int $menuId, array $tree): void;
}
