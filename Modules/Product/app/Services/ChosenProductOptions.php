<?php

namespace Modules\Product\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Modules\Option\Models\Option;
use Modules\Product\Models\Product;

/**
 * Class ChosenProductOptions
 *
 * Resolves and normalizes the selected options for a given product,
 * including both selectable options (dropdown, checkbox, etc.)
 * and text-based options (custom text input or freeform).
 *
 * This service ensures that all chosen options can be represented
 * as complete Option entities with their related OptionValue models,
 * ready to be used for pricing, cart building, and printing.
 *
 * Example use:
 *   $chosen = new ChosenProductOptions($product, [
 *       10 => [22, 23],        // Multiple-choice option IDs
 *       11 => 'No onions',     // Text-type option
 *   ]);
 *
 *   $entities = $chosen->getEntities(); // Returns a collection of Option models
 */
class ChosenProductOptions
{
    /**
     * Create a new ChosenProductOptions instance.
     *
     * @param Product $product
     * @param array<int|string, mixed> $chosenOptions
     *     An associative array of option_id => selected_value(s).
     *     Example:
     *     [
     *         1 => [5, 6],          // Multi-select option values
     *         2 => 'Custom text',   // Text input option
     *     ]
     */
    public function __construct(
        protected Product $product,
        protected array   $chosenOptions = []
    )
    {
        // Remove empty/null options to avoid unnecessary DB queries
        $this->chosenOptions = array_filter($chosenOptions);
    }

    /**
     * Retrieve all option entities (including text-based) chosen for this product.
     *
     * @return Collection<Option>
     */
    public function getEntities(): Collection
    {
        // Fetch product options and their selected values
        $productOptions = $this->product->options()
            ->with(['values' => function ($query) {
                $query->whereIn('id', Arr::flatten($this->chosenOptions));
            }])
            ->whereIn('id', array_keys($this->chosenOptions))
            ->get()
            ->filter(fn($productOption) => $productOption->values->isNotEmpty());

        // Merge text-type options that are not present in the above
        return $this->mergeTextTypeOptions($productOptions);
    }

    /**
     * Merge text-type (custom input) options into the selected options list.
     *
     * @param Collection<Option> $productOptions
     * @return Collection<Option>
     */
    private function mergeTextTypeOptions(Collection $productOptions): Collection
    {
        // Identify chosen options not yet included (text-type or special cases)
        $textOptionIds = collect($this->chosenOptions)
            ->reject(fn($_, $optionId) => $productOptions->contains('id', $optionId));

        // Fetch these options and hydrate with text labels
        $textTypeOptions = Option::with('values')
            ->whereIn('id', $textOptionIds->keys())
            ->get();

        // Combine the two sets of options
        $merged = $textOptionIds->map(function ($value, $optionId) use ($textTypeOptions) {
            /** @var Option|null $option */
            $option = $textTypeOptions->firstWhere('id', $optionId);

            if ($option && $option->values->isNotEmpty()) {
                // Attach the custom user-entered text as the label
                $option->values->first()->fill(['label' => $value]);
                return $option;
            }

            return null;
        })->filter();

        return $merged->merge($productOptions)->values();
    }
}
