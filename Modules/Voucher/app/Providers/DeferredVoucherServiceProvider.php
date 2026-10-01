<?php

namespace Modules\Voucher\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Voucher\Services\Voucher\VoucherService;
use Modules\Voucher\Services\Voucher\VoucherServiceInterface;

class DeferredVoucherServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->singleton(
            abstract: VoucherServiceInterface::class,
            concrete: fn($app) => $app->make(VoucherService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            VoucherServiceInterface::class,
        ];
    }
}
