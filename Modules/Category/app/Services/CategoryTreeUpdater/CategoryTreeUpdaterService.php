<?php

namespace Modules\Category\Services\CategoryTreeUpdater;

use Arr;
use Cache;
use DB;
use Modules\Category\Models\Category;

class CategoryTreeUpdaterService implements CategoryTreeUpdaterServiceInterface
{
    /** @inheritDoc */
    public function update(int $menuId, array $tree): void
    {
        [$ids, $parentIdCases, $orderCases, $params] = static::getDataForQuery($tree);

        $sql = "UPDATE `categories` SET `parent_id` = CASE `id` $parentIdCases END, `order` = CASE `id` $orderCases END, `updated_at` = ? WHERE `id` IN ($ids) AND `menu_id` = ?";

        $params[] = $menuId;

        DB::transaction(function () use ($sql, $params, $menuId) {
            DB::update($sql, $params);

            Category::query()->where('menu_id', $menuId)->fixTree();
        });

        Cache::tags('categories')->flush();
    }

    /**
     * Get data for update query.
     *
     * @param array $tree
     *
     * @return array
     */
    private function getDataForQuery(array $tree): array
    {
        $params = [];
        $ids = [];
        $cases = [];

        foreach (static::getAttributesList($tree) as $id => $values) {
            foreach ($values as $column => $value) {
                $cases[$column][] = "WHEN $id THEN ?";
                $params[$column][] = $value;
            }

            $ids[] = $id;
        }

        return $this->prepareData($ids, $cases, $params);
    }

    /**
     * Get attributes list from the given tree.
     *
     * @param array $tree
     *
     * @return array
     */
    private function getAttributesList(array $tree): array
    {
        $attributes = [];

        foreach ($tree as $order => $category) {
            $attributes[$category['id']] = [
                'parent_id' => $category['parent'],
                'order' => $order,
            ];
        }

        return $attributes;
    }

    /**
     * Prepare data for update query.
     *
     * @param array $ids
     * @param array $cases
     * @param array $params
     *
     * @return array
     */
    private static function prepareData(array $ids, array $cases, array $params): array
    {
        $ids = implode(',', $ids);
        $parentIdCases = implode(' ', $cases['parent_id']);
        $orderCases = implode(' ', $cases['order']);

        $params = Arr::flatten($params);
        $params[] = now();

        return [$ids, $parentIdCases, $orderCases, $params];
    }
}
