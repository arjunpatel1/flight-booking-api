<?php

namespace Modules\Cart\Http\Requests\Api\V1;

use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Request;
use Modules\Option\Enums\OptionType;
use Modules\Option\Models\Option;
use Modules\Product\Models\Product;

class StoreCartItemRequest extends Request
{
    protected Product $product;

    /**
     * Create a new instance of StoreCartItemRequest
     *
     * @param ...$args
     */
    public function __construct(...$args)
    {
        parent::__construct(...$args);

        $this->product = $this->getProduct();
    }

    /**
     * Get product
     *
     * @return Product
     */
    private function getProduct(): Product
    {
        return Product::with(['options.values', 'files', 'categories', 'taxes', 'menu', 'branch'])
            ->whereHas('menu',
                fn($query) => $query
                    ->whereNull("deleted_at")
                    ->where('is_active', true)
                    ->where('branch_id', auth()->user()->effective_branch->id)
            )
            ->findOrFail(request()->input('product_id'));
    }

    public function product(): Product
    {
        return $this->product;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            "qty" => "required|numeric|min:1|max:1",
            "seat_number" => "nullable|integer|min:1|max:99",
            "course_number" => "nullable|integer|min:1|max:20",
            ...$this->getOptionsRules($this->product->options)
        ];
    }

    /**
     * Get rules for the given options.
     *
     * @param Collection $options
     *
     * @return array
     */
    private function getOptionsRules(Collection $options): array
    {
        return $options
            ->flatMap(fn(Option $option) => ["options.$option->id" => $this->getOptionRules($option)])
            ->all();
    }

    /**
     * Get rules for the given option.
     *
     * @param Option $option
     *
     * @return array
     */
    private function getOptionRules(Option $option): array
    {
        $rules = [];

        if ($option->is_required) {
            $rules[] = 'required';
        }

        if (in_array($option->type, [OptionType::Radio->value, OptionType::Select->value])) {
            $rules[] = Rule::in($option->values->map->id->all());
        }

        return $rules;
    }

    /**
     * Get data to be validated from the request.
     *
     * @return array
     */
    public function validationData(): array
    {
        return array_merge(
            $this->all(),
            [
                'options' => array_filter($this->options ?? []),
            ]
        );
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'options.*.required' => __('cart::validation.this_field_is_required'),
            'options.*.in' => __('cart::validation.the_selected_option_is_invalid'),
        ];
    }

    /** @inheritDoc */
    protected function availableAttributes(): string
    {
        return "cart::attributes.items";
    }
}
