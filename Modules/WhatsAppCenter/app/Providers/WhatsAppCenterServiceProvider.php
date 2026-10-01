<?php

namespace Modules\WhatsAppCenter\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\WhatsAppCenter\Services\WhatsAppCenterService;
use Modules\WhatsAppCenter\Services\WhatsAppReportShareService;
use Modules\Product\Models\Product;
use Modules\WhatsAppCenter\Jobs\SyncWhatsAppCatalogProduct;
use Modules\WhatsAppCenter\Models\WhatsAppCatalogProduct;

class WhatsAppCenterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppCenterService::class);
        $this->app->singleton(WhatsAppReportShareService::class);
    }

    public function boot(): void
    {
        $queueExistingMappings = static function (Product $product): void {
            WhatsAppCatalogProduct::query()->withoutGlobalTenant()
                ->where('product_id', $product->id)
                ->pluck('id')
                ->each(function (int $mappingId): void {
                    WhatsAppCatalogProduct::query()->withoutGlobalTenant()->whereKey($mappingId)
                        ->update(['sync_status' => 'pending', 'last_sync_error' => null]);
                    SyncWhatsAppCatalogProduct::dispatch($mappingId)->afterCommit();
                });
        };

        Product::saved($queueExistingMappings);
        Product::deleted($queueExistingMappings);
    }
}
