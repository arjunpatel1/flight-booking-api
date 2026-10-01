<?php

namespace Modules\Order\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Order\Services\Order\OrderService;
use Modules\Order\Services\Order\OrderServiceInterface;
use Modules\Order\Services\OrderCreate\CreateOrderService;
use Modules\Order\Services\OrderCreate\CreateOrderServiceInterface;
use Modules\Order\Services\OrderFeedback\OrderFeedbackService;
use Modules\Order\Services\OrderFeedback\OrderFeedbackServiceInterface;
use Modules\Order\Services\OrderPayment\OrderPaymentService;
use Modules\Order\Services\OrderPayment\OrderPaymentServiceInterface;
use Modules\Order\Services\Reason\ReasonService;
use Modules\Order\Services\Reason\ReasonServiceInterface;
use Modules\Order\Services\SaveOrder\SaveOrderService;
use Modules\Order\Services\SaveOrder\SaveOrderServiceInterface;
use Modules\Order\Services\SplitOrder\SplitOrderService;
use Modules\Order\Services\SplitOrder\SplitOrderServiceInterface;
use Modules\Order\Delivery\DeliveryProvider;
use Modules\Order\Delivery\UengageDeliveryProvider;

class DeferredOrderServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Boot the application events.
     */
    public function register(): void
    {
        $this->app->bind(DeliveryProvider::class, UengageDeliveryProvider::class);
        $this->app->singleton(
            abstract: OrderServiceInterface::class,
            concrete: fn ($app) => $app->make(OrderService::class)
        );

        $this->app->scoped(
            abstract: CreateOrderServiceInterface::class,
            concrete: fn ($app) => $app->make(CreateOrderService::class)
        );

        $this->app->scoped(
            abstract: SaveOrderServiceInterface::class,
            concrete: fn ($app) => $app->make(SaveOrderService::class)
        );

        $this->app->singleton(
            abstract: ReasonServiceInterface::class,
            concrete: fn ($app) => $app->make(ReasonService::class)
        );

        $this->app->singleton(
            abstract: OrderPaymentServiceInterface::class,
            concrete: fn ($app) => $app->make(OrderPaymentService::class)
        );

        $this->app->singleton(
            abstract: OrderFeedbackServiceInterface::class,
            concrete: fn ($app) => $app->make(OrderFeedbackService::class)
        );

        $this->app->singleton(
            abstract: SplitOrderServiceInterface::class,
            concrete: fn ($app) => $app->make(SplitOrderService::class)
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            DeliveryProvider::class,
            OrderServiceInterface::class,
            CreateOrderServiceInterface::class,
            OrderPaymentServiceInterface::class,
            OrderFeedbackServiceInterface::class,
            SaveOrderServiceInterface::class,
            ReasonServiceInterface::class,
            SplitOrderServiceInterface::class,
        ];
    }
}
