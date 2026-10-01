<?php

namespace Modules\Product\Services\ProductOption;

use Arr;
use Illuminate\Support\Collection;
use Modules\Option\Models\Option;
use Modules\Product\Models\Product;

class ProductOptionService implements ProductOptionServiceInterface
{
    /** @inheritDoc */
    public function syncOptions(Product $product, array $options): void
    {
        $ids = $this->getDeleteCandidates($product, $options);

        if ($ids->isNotEmpty()) {
            $product->options()->detach($ids);
        }

        $counter = 0;

        foreach (array_reset_index($this->getOptions($options)) as $attributes) {
            $attributes['is_global'] = false;
            $attributes['order'] = ++$counter;

            /** @var Option $option */
            $option = $product->options()->updateOrCreate(
                [
                    'id' => $attributes['id'] ?? null,
                    "branch_id" => $product->branch->id
                ],
                Arr::except($attributes, ['values'])
            );

            $option->saveValues($attributes['values'] ?? []);

        }
    }

    /**
     * Get delete candidates
     *
     * @param Product $product
     * @param array $options
     * @return Collection
     */
    protected function getDeleteCandidates(Product $product, array $options): Collection
    {
        return $product->options()
            ->pluck('id')
            ->diff(Arr::pluck($this->getOptions($options), 'id'));
    }

    /**
     * Get product options
     *
     * @param array $options
     * @return array
     */
    protected function getOptions(array $options): array
    {
        return array_filter($options, fn($option) => isset($option['name']));
    }
}
