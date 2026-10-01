<?php

namespace Modules\Product\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Product\Console\Commands\OptimizeImages;
use Modules\Product\Console\Commands\OptimizeThumbnails;

class ProductServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                OptimizeImages::class,
                OptimizeThumbnails::class,
            ]);
        }
    }
}
