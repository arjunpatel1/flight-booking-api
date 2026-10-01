<?php

namespace Tests\Feature\Product;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Pos\Transformers\Api\V1\Pos\PosProductResource;
use Modules\Product\Models\Product;
use Modules\Product\Services\Product\ProductService;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class ProductAllergenTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        setting(['default_currency' => 'INR']);
    }

    public function test_allergens_and_dietary_labels_cast_to_arrays(): void
    {
        $branch = $this->makeBranch();
        $menu = $this->makeMenu($branch);
        $product = Product::factory()->create([
            'menu_id' => $menu->id,
            'allergens' => ['gluten', 'nuts'],
            'dietary_labels' => ['vegetarian'],
        ]);

        $fresh = Product::query()->withoutGlobalScopes()->findOrFail($product->id);

        $this->assertSame(['gluten', 'nuts'], $fresh->allergens);
        $this->assertSame(['vegetarian'], $fresh->dietary_labels);
    }

    public function test_pos_resource_exposes_allergens(): void
    {
        $branch = $this->makeBranch();
        $menu = $this->makeMenu($branch);
        $product = Product::factory()->create([
            'menu_id' => $menu->id,
            'allergens' => ['milk'],
            'dietary_labels' => [],
        ]);

        $payload = (new PosProductResource($product))->toArray(Request::create('/'));

        $this->assertSame(['milk'], $payload['allergens']);
        $this->assertSame([], $payload['dietary_labels']);
    }

    public function test_pos_resource_handles_a_lightweight_product_projection_without_food_type(): void
    {
        $branch = $this->makeBranch();
        $menu = $this->makeMenu($branch);
        $product = Product::factory()->create([
            'menu_id' => $menu->id,
            'food_type' => 'veg',
        ]);

        $projected = Product::query()
            ->withoutGlobalScopes()
            ->select([
                'id',
                'sku',
                'name',
                'description',
                'price',
                'special_price',
                'special_price_type',
                'special_price_start',
                'special_price_end',
                'new_from',
                'new_to',
                'is_available',
                'is_recommended',
                'is_best_seller',
                'display_priority',
                'notes',
                'hsn_code',
                'menu_id',
                'image_thumbnail_path',
                'created_at',
                'updated_at',
            ])
            ->findOrFail($product->id);

        $payload = (new PosProductResource($projected))->toArray(Request::create('/'));

        $this->assertNull($payload['food_type']);
    }

    public function test_defaults_to_empty_arrays_when_null(): void
    {
        $branch = $this->makeBranch();
        $menu = $this->makeMenu($branch);
        $product = Product::factory()->create(['menu_id' => $menu->id]);

        $payload = (new PosProductResource($product))->toArray(Request::create('/'));

        $this->assertSame([], $payload['allergens']);
        $this->assertSame([], $payload['dietary_labels']);
    }

    public function test_pos_resource_exposes_offline_search_keyword_variants(): void
    {
        $branch = $this->makeBranch();
        $menu = $this->makeMenu($branch);
        $product = Product::factory()->create([
            'menu_id' => $menu->id,
            'name' => ['en' => 'Paneer Butter Masala'],
            'sku' => 'PBM-01',
            'hsn_code' => '996331',
            'notes' => 'North Indian Curry',
        ]);

        $payload = (new PosProductResource($product))->toArray(Request::create('/'));

        $this->assertContains('paneer butter masala', $payload['search_keywords']);
        $this->assertContains('paneerbuttermasala', $payload['search_keywords']);
        $this->assertContains('pbm', $payload['search_keywords']);
        $this->assertContains('pbm01', $payload['search_keywords']);
        $this->assertContains('996331', $payload['search_keywords']);
        $this->assertContains('north indian curry', $payload['search_keywords']);
    }

    public function test_pos_resource_exposes_restaurant_memory_signals(): void
    {
        $branch = $this->makeBranch();
        $menu = $this->makeMenu($branch);
        $product = Product::factory()->create(['menu_id' => $menu->id]);
        $product->setAttribute('pos_memory', [
            'shift' => 'lunch',
            'recommendation_score' => 640,
            'waiter_score' => 120,
            'table_score' => 80,
            'pairing_product_ids' => [22, '23'],
            'pairings' => [
                ['product_id' => 22, 'score' => 5],
                ['product_id' => 0, 'score' => 99],
            ],
        ]);

        $payload = (new PosProductResource($product))->toArray(Request::create('/'));

        $this->assertSame('lunch', $payload['memory']['shift']);
        $this->assertSame(640, $payload['memory']['recommendation_score']);
        $this->assertSame(120, $payload['memory']['waiter_score']);
        $this->assertSame(80, $payload['memory']['table_score']);
        $this->assertSame([22, 23], $payload['memory']['pairing_product_ids']);
        $this->assertSame([['product_id' => 22, 'score' => 5]], $payload['memory']['pairings']);
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function test_merchandising_recommendations_support_projected_best_seller_products(): void
    {
        $branch = $this->makeBranch();
        $menu = $this->makeMenu($branch);
        $product = Product::factory()->create([
            'menu_id' => $menu->id,
            'name' => ['en' => 'Legacy bestseller'],
            'is_best_seller' => true,
            'display_priority' => 1,
        ]);

        $recommendations = app(ProductService::class)->merchandisingRecommendations($menu);

        $this->assertSame($menu->id, $recommendations['menu_id']);
        $this->assertSame($product->id, $recommendations['stale_best_sellers'][0]['product_id']);
    }
}
