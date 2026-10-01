<?php

namespace Tests\Feature\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Category\Models\Category;
use Modules\Import\Services\Adapters\ProductImportAdapter;
use Modules\Pricing\Models\PriceType;
use Modules\Product\Models\Product;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class ProductImportPriceTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();

        setting(['default_locale' => 'en']);
        app(SettingServiceInterface::class)->refreshSettingBinding();
    }

    public function test_product_import_persists_half_and_full_prices(): void
    {
        $menu = $this->makeMenu();
        $half = $this->priceType('HALF', 'Half');
        $full = $this->priceType('FULL', 'Full');

        app(ProductImportAdapter::class)->import([
            'name_en' => 'Imported Curry',
            'menu_id' => $menu->id,
            'sku' => 'IMPORT-HALF-FULL',
            'price' => 100,
            'Half Price' => '65.50',
            'FULL_PRICE' => '120',
        ]);

        $product = Product::query()
            ->withoutGlobalScopes()
            ->where('sku', 'IMPORT-HALF-FULL')
            ->firstOrFail();

        $prices = $product->productPrices()
            ->pluck('price', 'price_type_id');

        $this->assertSame(65.5, (float) $prices->get($half->id));
        $this->assertSame(120.0, (float) $prices->get($full->id));
    }

    public function test_blank_size_price_does_not_create_a_zero_price_override(): void
    {
        $menu = $this->makeMenu();
        $half = $this->priceType('HALF', 'Half');
        $full = $this->priceType('FULL', 'Full');

        app(ProductImportAdapter::class)->import([
            'name_en' => 'Imported Soup',
            'menu_id' => $menu->id,
            'sku' => 'IMPORT-BLANK-HALF',
            'price' => 90,
            'half_price' => ' ',
            'full_size_price' => '140.25',
        ]);

        $product = Product::query()
            ->withoutGlobalScopes()
            ->where('sku', 'IMPORT-BLANK-HALF')
            ->firstOrFail();

        $this->assertFalse($product->productPrices()->where('price_type_id', $half->id)->exists());
        $this->assertSame(
            140.25,
            (float) $product->productPrices()->where('price_type_id', $full->id)->value('price')
        );
    }

    public function test_category_name_creates_once_and_assigns_each_imported_product(): void
    {
        $menu = $this->makeMenu();
        $adapter = app(ProductImportAdapter::class);

        foreach ([['Curry', 'IMPORT-CURRY-1'], [' curry ', 'IMPORT-CURRY-2']] as [$category, $sku]) {
            $adapter->import([
                'name_en' => $sku,
                'menu_id' => $menu->id,
                'sku' => $sku,
                'price' => 100,
                'category_name' => $category,
            ]);
        }

        $this->assertSame(1, Category::query()->where('menu_id', $menu->id)->count());
        $categoryId = Category::query()->where('menu_id', $menu->id)->value('id');
        foreach (['IMPORT-CURRY-1', 'IMPORT-CURRY-2'] as $sku) {
            $product = Product::query()->where('sku', $sku)->firstOrFail();
            $this->assertSame([$categoryId], $product->categories()->pluck('categories.id')->all());
        }
    }

    private function priceType(string $code, string $name): PriceType
    {
        return PriceType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => ['en' => $name],
                'rule_type' => 'fixed',
                'rule_value' => 0,
                'is_active' => true,
            ]
        );
    }
}
