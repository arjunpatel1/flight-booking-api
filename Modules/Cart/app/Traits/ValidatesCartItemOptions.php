<?php

namespace Modules\Cart\Traits;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Option\Enums\OptionType;
use Modules\Option\Models\Option;
use Modules\Product\Models\Product;

trait ValidatesCartItemOptions
{
    /** Convert opaque public option/value references to internal keys. */
    protected function normalizeCartItemOptionReferences(Product $product, array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            $option = $product->options->firstWhere('uuid', (string) $key)
                ?? (ctype_digit((string) $key) ? $product->options->firstWhere('id', (int) $key) : null);
            if (! $option) {
                $normalized[$key] = $value;
                continue;
            }

            if (in_array($option->type, [OptionType::Radio, OptionType::Select, OptionType::Checkbox, OptionType::MultipleSelect], true)) {
                $values = is_array($value) ? $value : [$value];
                $values = collect($values)->map(function ($reference) use ($option) {
                    $matched = $option->values->firstWhere('uuid', (string) $reference)
                        ?? (ctype_digit((string) $reference) ? $option->values->firstWhere('id', (int) $reference) : null);

                    return $matched?->id ?? $reference;
                })->all();
                $value = in_array($option->type, [OptionType::Radio, OptionType::Select], true)
                    ? ($values[0] ?? null)
                    : $values;
            }

            $normalized[(string) $option->id] = $value;
        }

        return $normalized;
    }

    /**
     * Validate cart item options using the same option rules as single-item writes.
     */
    protected function validateCartItemOptions(Product $product, array $options): void
    {
        $unknownOptionIds = collect(array_keys($options))
            ->map(fn ($id) => (int) $id)
            ->diff($product->options->pluck('id'));

        if ($unknownOptionIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'options' => __('cart::validation.the_selected_option_is_invalid'),
            ]);
        }

        Validator::make(
            ['options' => array_filter($options, fn ($value) => $value !== null && $value !== '')],
            $this->getCartItemOptionRules($product->options),
            [
                'options.*.required' => __('cart::validation.this_field_is_required'),
                'options.*.in' => __('cart::validation.the_selected_option_is_invalid'),
            ],
        )->validate();
    }

    /**
     * Get rules for the given options.
     */
    private function getCartItemOptionRules(Collection $options): array
    {
        return $options
            ->flatMap(function (Option $option): array {
                $key = "options.$option->id";
                $rules = [$key => $this->getCartItemOptionRulesFor($option)];

                if (in_array($option->type, [OptionType::Checkbox, OptionType::MultipleSelect], true)) {
                    $rules["{$key}.*"] = Rule::in($option->values->map->id->all());
                }

                return $rules;
            })
            ->all();
    }

    /**
     * Get rules for the given option.
     */
    private function getCartItemOptionRulesFor(Option $option): array
    {
        $rules = [];

        if ($option->is_required) {
            $rules[] = 'required';
        }

        if (in_array($option->type, [OptionType::Radio, OptionType::Select], true)) {
            $rules[] = Rule::in($option->values->map->id->all());
        }

        if (in_array($option->type, [OptionType::Checkbox, OptionType::MultipleSelect], true)) {
            $rules[] = 'array';
        }

        return $rules;
    }
}
