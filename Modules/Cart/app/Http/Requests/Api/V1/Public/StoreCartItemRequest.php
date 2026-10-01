<?php

namespace Modules\Cart\Http\Requests\Api\V1\Public;

use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Core\Http\Requests\Request;
use Modules\Option\Models\Option;
use Modules\Order\Enums\OrderType;
use Modules\Product\Models\Product;

class StoreCartItemRequest extends Request
{
    protected ?Product $product = null;

    protected function prepareForValidation(): void
    {
        $menu = null;
        if ($this->filled('menu_reference') && Str::isUuid((string) $this->input('menu_reference'))) {
            $menu = PublicTenantGuard::menu($this, (string) $this->input('menu_reference'));
            $this->merge(['branch_id' => $menu->branch_id]);
        }

        if ($this->filled('product_reference') && Str::isUuid((string) $this->input('product_reference'))) {
            $branchId = $menu?->branch_id ?? $this->input('branch_id');
            if (filled($branchId)) {
                $branch = PublicTenantGuard::branch($this, $branchId);
                $product = Product::query()->with('options.values')
                    ->where('uuid', $this->input('product_reference'))
                    ->where('is_active', true)
                    ->whereHas('menu', fn ($query) => $query
                        ->whereNull('deleted_at')
                        ->where('is_active', true)
                        ->when($menu, fn ($query) => $query->whereKey($menu->id))
                        ->where('branch_id', $branch->id))
                    ->first();
                if ($product) {
                    $this->product = $product;
                    $this->merge(['product_id' => $product->id]);
                }
            }
        }
    }

    /**
     * Create a new instance of StoreCartItemRequest
     *
     * @param ...$args
     */
    public function __construct(...$args)
    {
        parent::__construct(...$args);
        
        // Only load product if validation passes
        // This prevents premature loading before validation
    }

    /**
     * Get product
     *
     * @return Product
     */
    private function getProduct(): Product
    {
        if (!isset($this->product)) {
            $branchId = $this->getBranchId();
            PublicTenantGuard::branch($this, $branchId);
            
            $this->product = Product::with('options')
                ->whereHas('menu',
                    fn($query) => $query
                        ->whereNull("deleted_at")
                        ->where('is_active', true)
                        ->where('branch_id', $branchId)
                )
                ->select('id', 'is_active', 'menu_id')
                ->findOrFail($this->input('product_id'));
        }
        
        return $this->product;
    }

    /**
     * Get branch ID from request
     *
     * @return int
     */
    private function getBranchId(): int
    {
        // Try to get branch_id from request first
        if ($this->has('branch_id') && !empty($this->input('branch_id'))) {
            PublicTenantGuard::branch($this, $this->input('branch_id'));

            return (int) $this->input('branch_id');
        }

        // If no branch_id in request, return 0 to trigger validation error
        return 0;
    }

    /**
     * Get the validation rules that apply to the request
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'menu_reference' => ['nullable', 'uuid'],
            'product_reference' => [
                'nullable',
                'uuid',
                'required_without:product_id',
                function ($attribute, $value, $fail): void {
                    if (filled($value) && ! isset($this->product)) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
            ],
            'product_id' => [
                'required_without:product_reference',
                function ($attribute, $value, $fail) {
                    // Additional validation to ensure product is active and available
                    $branchId = $this->getBranchId();
                    $product = Product::query()
                        ->whereHas('menu',
                            fn($query) => $query
                                ->whereNull("deleted_at")
                                ->where('is_active', true)
                                ->where('branch_id', $branchId)
                        )
                        ->find($value);
                    if (!$product) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                        return;
                    }
                    if ($product && !$product->is_active) {
                        $fail('The selected product is not active.');
                    }
                }
            ],
            'qty' => 'required|numeric|min:1|max:999',
            'branch_id' => 'required|integer',
            'order_type' => ['nullable', Rule::enum(OrderType::class)],
            'seat_number' => 'nullable|integer|min:1|max:99',
        ];

        // Only add option rules if product exists and has options
        try {
            $product = $this->getProduct();
            if ($product && $product->options && $product->options->isNotEmpty()) {
                $rules = array_merge($rules, $this->getOptionsRules($product->options));
            }
        } catch (\Exception $e) {
            // If product validation fails, skip option rules
            // Basic validation above will catch the error
        }

        return $rules;
    }

    /**
     * Get rules for the given options
     *
     * @param Collection $options
     * @return array
     */
    private function getOptionsRules(Collection $options): array
    {
        return $options
            ->flatMap(fn(Option $option) => ["options.$option->id" => $this->getOptionRules($option)])
            ->all();
    }

    /**
     * Get rules for the given option
     *
     * @param Option $option
     * @return array
     */
    private function getOptionRules(Option $option): array
    {
        $rules = [];

        if ($option->is_required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        if (in_array($option->type, ['radio', 'select'])) {
            $rules[] = Rule::in($option->values->map->id->all());
        }

        if (in_array($option->type, ['text', 'textarea'])) {
            $rules[] = 'string';
            $rules[] = 'max:1000';
        }

        if (in_array($option->type, ['multiple_select', 'checkbox'])) {
            $rules[] = 'array';
            $rules[] = 'min:0';
        }

        if ($option->type === 'date') {
            $rules[] = 'date';
        }

        if ($option->type === 'time') {
            $rules[] = 'date_format:H:i';
        }

        return $rules;
    }

    /**
     * Get data to be validated from the request
     *
     * @return array
     */
    public function validationData(): array
    {
        return array_merge(
            $this->all(),
            [
                'options' => array_filter($this->options ?? [], function ($value) {
                    return $value !== null && $value !== '';
                }),
            ]
        );
    }

    /**
     * Get custom messages for validator errors
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'options.*.required' => __('cart::validation.this_field_is_required'),
            'options.*.in' => __('cart::validation.the_selected_option_is_invalid'),
            'options.*.string' => 'This field must be text',
            'options.*.numeric' => 'This field must be a number',
            'options.*.max' => 'This field may not be greater than :max characters',
            'branch_id.required' => 'Branch ID is required',
            'branch_id.exists' => 'Invalid branch',
            'product_id.exists' => 'The selected product is invalid',
            'qty.max' => 'Quantity may not be greater than 999',
        ];
    }

    /**
     * Get the available attributes for the request
     *
     * @return string
     */
    protected function availableAttributes(): string
    {
        return "cart::attributes.items";
    }
}
