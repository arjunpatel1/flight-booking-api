<?php

namespace Modules\Import\Services\Adapters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Category\Models\Category;
use Modules\Import\Contracts\ImportAdapter;
use Modules\Import\Services\Adapters\Concerns\BuildsTranslatedValues;
use Modules\Pricing\Models\PriceType;
use Modules\Product\Services\Product\ProductServiceInterface;

class ProductImportAdapter implements ImportAdapter
{
    use BuildsTranslatedValues;

    public function __construct(private readonly ProductServiceInterface $products) {}

    public function import(array $row, array $options = []): void
    {
        $menuId = $row['menu_id'] ?? $options['menu_id'] ?? null;
        $tenantId = isset($options['tenant_id']) ? (int) $options['tenant_id'] : null;
        $menuExists = Rule::exists('menus', 'id')->whereNull('deleted_at');
        if ($tenantId) {
            $menuExists->where(fn ($query) => $query->whereIn(
                'branch_id',
                fn ($branches) => $branches->select('id')->from('branches')->where('tenant_id', $tenantId)
            ));
        }

        Validator::make(['menu_id' => $menuId], ['menu_id' => ['required', 'integer', $menuExists]])->validate();

        DB::transaction(function () use ($row, $menuId, $menuExists) {
            $data = [
                'name' => $this->translated($row, 'name'),
                'description' => $this->translated($row, 'description', false),
                'menu_id' => $menuId,
                'sku' => $row['sku'] ?? null,
                'price' => $row['price'] ?? null,
                'is_active' => $this->boolean($row, 'is_active', true),
                'is_available' => $this->boolean($row, 'is_available', true),
                'is_recommended' => $this->boolean($row, 'is_recommended', false),
                'is_best_seller' => $this->boolean($row, 'is_best_seller', false),
                'display_priority' => $row['display_priority'] ?? 0,
                'categories' => $this->categoryIds($row, (int) $menuId),
                'taxes' => $this->ids($row['tax_ids'] ?? null),
                'product_prices' => $this->productPrices($row),
            ];

            Validator::make($data, [
                'name' => ['required', 'array'],
                'menu_id' => ['required', 'integer', $menuExists],
                'sku' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('products', 'sku')->where('menu_id', $menuId),
                ],
                'price' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
                'is_active' => ['required', 'boolean'],
                'is_available' => ['required', 'boolean'],
                'is_recommended' => ['required', 'boolean'],
                'is_best_seller' => ['required', 'boolean'],
                'display_priority' => ['nullable', 'integer', 'min:0', 'max:9999'],
                'categories' => ['array'],
                'categories.*' => ['integer', "exists:categories,id,deleted_at,NULL,is_active,1,menu_id,{$menuId}"],
                'taxes' => ['array'],
                'taxes.*' => ['integer', 'exists:taxes,id,deleted_at,NULL,is_global,0'],
            ])->validate();

            $this->products->store($data);
        });
    }

    private function categoryIds(array $row, int $menuId): array
    {
        $ids = $this->ids($row['category_ids'] ?? $row['category_id'] ?? null);
        if ($ids !== [] || $menuId < 1) {
            return $ids;
        }

        $names = collect(preg_split('/[,|]/', (string) ($row['category_names'] ?? $row['category_name'] ?? $row['category'] ?? '')))
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique(fn (string $name) => Str::lower($name));

        if ($names->isEmpty()) {
            return [];
        }

        $existing = Category::query()->withoutGlobalActive()->where('menu_id', $menuId)->lockForUpdate()->get();

        return $names->map(function (string $name) use ($existing, $menuId) {
            $match = $existing->first(function (Category $category) use ($name) {
                $values = $category->getTranslations('name');

                return collect($values)->contains(fn ($value) => Str::lower(trim((string) $value)) === Str::lower($name));
            });
            if ($match) {
                if (! $match->is_active) {
                    $match->update(['is_active' => true]);
                }

                return $match->id;
            }
            $slug = Str::slug($name);
            Validator::make(['category_name' => $name, 'category_slug' => $slug], [
                'category_name' => ['required', 'string', 'max:255'],
                'category_slug' => ['required', 'string', 'max:255', Rule::unique('categories', 'slug')->where('menu_id', $menuId)],
            ])->validate();
            $created = Category::query()->create(['name' => ['en' => $name], 'slug' => $slug, 'menu_id' => $menuId, 'is_active' => true]);
            $existing->push($created);

            return $created->id;
        })->values()->all();
    }

    private function productPrices(array $row): array
    {
        $normalizedRow = $this->normalizeRowKeys($row);

        return PriceType::query()->active()->get(['id', 'name', 'code'])
            ->map(function (PriceType $type) use ($normalizedRow) {
                $value = $this->firstRowValue($normalizedRow, $this->priceAliases($type));

                if ($value === null || $value === '' || ! is_numeric($value)) {
                    return null;
                }

                return ['price_type_id' => $type->id, 'is_global' => false, 'price' => (float) $value];
            })->filter()->values()->all();
    }

    private function normalizeRowKeys(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$this->normalizeColumn((string) $key)] = $value;
        }

        return $normalized;
    }

    private function firstRowValue(array $normalizedRow, array $aliases): mixed
    {
        foreach ($aliases as $alias) {
            $key = $this->normalizeColumn($alias);

            if (array_key_exists($key, $normalizedRow)) {
                return $normalizedRow[$key];
            }
        }

        return null;
    }

    private function priceAliases(PriceType $type): array
    {
        $code = $this->normalizeColumn((string) $type->code);
        $name = $this->normalizeColumn((string) $type->name);
        $aliases = [
            "price_{$code}",
            "{$code}_price",
            "price_{$name}",
            "{$name}_price",
            "{$code}",
            "{$name}",
        ];

        if (Str::contains($code, 'half') || Str::contains($name, 'half')) {
            $aliases = array_merge($aliases, [
                'half',
                'half_price',
                'price_half',
                'half_size_price',
                'halfsize_price',
                'half_portion_price',
                'half_rate',
                'half_amount',
            ]);
        }

        if (Str::contains($code, 'full') || Str::contains($name, 'full')) {
            $aliases = array_merge($aliases, [
                'full',
                'full_price',
                'price_full',
                'full_size_price',
                'fullsize_price',
                'regular_price',
                'full_rate',
                'full_amount',
            ]);
        }

        return array_values(array_unique($aliases));
    }

    private function normalizeColumn(string $column): string
    {
        return (string) Str::of($column)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');
    }

    private function ids(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value));
        }

        return collect(explode(',', (string) $value))
            ->map(fn (string $id) => trim($id))
            ->filter()
            ->map(fn (string $id) => (int) $id)
            ->values()
            ->all();
    }
}
