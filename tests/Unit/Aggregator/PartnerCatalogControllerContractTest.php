<?php

namespace Tests\Unit\Aggregator;

use Tests\TestCase;

class PartnerCatalogControllerContractTest extends TestCase
{
    public function test_partner_product_catalog_serialization_has_safe_fallbacks(): void
    {
        $controller = file_get_contents(base_path('Modules/Aggregator/app/Http/Controllers/Api/V1/PartnerCatalogController.php'));

        $this->assertStringContainsString('productPriceAmount', $controller);
        $this->assertStringContainsString('getRawOriginal(\'price\')', $controller);
        $this->assertStringContainsString('productCurrency', $controller);
        $this->assertStringContainsString('productImageUrl', $controller);
        $this->assertStringContainsString('catch (Throwable)', $controller);
    }

    public function test_partner_catalog_and_orders_exclude_soft_deleted_products(): void
    {
        $catalog = file_get_contents(base_path('Modules/Aggregator/app/Http/Controllers/Api/V1/PartnerCatalogController.php'));
        $orders = file_get_contents(base_path('Modules/Aggregator/app/Http/Controllers/Api/V1/PartnerOrderController.php'));

        $this->assertStringContainsString("->where('menu_id', \$menu->id)\n            ->whereNull('deleted_at')", $catalog);
        $this->assertStringContainsString("->whereKey(\$productId)->whereNull('deleted_at')", $orders);
        $this->assertStringContainsString("'files'", $catalog);
        $this->assertStringContainsString('thumbnail?->preview_image_url', $catalog);
    }
}
