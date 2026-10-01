<?php

namespace Modules\Order\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Order\Events\DeliveryStatusChanged;
use Modules\Order\Events\OrderCreated;
use Modules\Order\Events\OrderMergeBillingPaid;
use Modules\Order\Events\OrderPaid;
use Modules\Order\Events\OrderUpdated;
use Modules\Order\Events\OrderUpdateStatus;
use Modules\Order\Events\OrderVoided;
use Modules\Order\Jobs\EscalateUnassignedDeliveries;
use Modules\Order\Jobs\SyncUengageDeliveries;
use Modules\Order\Listeners\CreateOrderCreditNote;
use Modules\Order\Listeners\CreateOrderPaidInvoice;
use Modules\Order\Listeners\DeductOrderStock;
use Modules\Order\Listeners\HandleOrderLoyaltyPoints;
use Modules\Order\Listeners\InvalidateOrderReadCaches;
use Modules\Order\Listeners\MarkDeliveryAfterOrderCancellation;
use Modules\Order\Listeners\MarkTableAsFree;
use Modules\Order\Listeners\NotifyTenantAdminsOfCancelledOrder;
use Modules\Order\Listeners\NotifyTenantAdminsOfDeliveryStatus;
use Modules\Order\Listeners\NotifyTenantAdminsOfNewOrder;
use Modules\Order\Listeners\OrderRefundAmount;
use Modules\Order\Listeners\PrintKitchenTicket;
use Modules\Order\Listeners\RestoreOrderStock;
use Modules\Order\Listeners\RouteOrderToKitchenStations;
use Modules\Order\Listeners\SendDeliveryWhatsAppNotification;
use Modules\Order\Listeners\SendOrderEmailNotification;
use Modules\Order\Listeners\SendOrderWhatsAppNotification;
use Modules\Order\Listeners\SettleDeliveryWalletAfterCancellation;
use Modules\Order\Listeners\StartDeliveryAfterKitchenAcceptance;
use Modules\Order\Listeners\StoreOrderStatusLogo;
use Modules\Order\Listeners\SyncUpdatedOrderStock;
use Modules\Order\Listeners\UpdateCustomerExperience;
use Modules\Order\Listeners\UpdateOrderProductStatus;

class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        parent::boot();
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->job(new SyncUengageDeliveries)
                ->name('order:sync-uengage-deliveries')
                ->everyMinute()
                ->withoutOverlapping();
            $this->app->make(Schedule::class)
                ->job(new EscalateUnassignedDeliveries)
                ->name('order:escalate-unassigned-deliveries')
                ->everyMinute()->withoutOverlapping();
        });
    }

    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        DeliveryStatusChanged::class => [
            SettleDeliveryWalletAfterCancellation::class,
            NotifyTenantAdminsOfDeliveryStatus::class,
            SendDeliveryWhatsAppNotification::class,
        ],
        OrderUpdateStatus::class => [
            NotifyTenantAdminsOfCancelledOrder::class,
            StartDeliveryAfterKitchenAcceptance::class,
            MarkDeliveryAfterOrderCancellation::class,
            InvalidateOrderReadCaches::class,
            RouteOrderToKitchenStations::class,
            StoreOrderStatusLogo::class,
            MarkTableAsFree::class,
            DeductOrderStock::class,
            UpdateOrderProductStatus::class,
            HandleOrderLoyaltyPoints::class,
            PrintKitchenTicket::class,
            SendOrderWhatsAppNotification::class,
            SendOrderEmailNotification::class,
            UpdateCustomerExperience::class,
        ],
        OrderVoided::class => [
            InvalidateOrderReadCaches::class,
            OrderRefundAmount::class,
            RestoreOrderStock::class,
            CreateOrderCreditNote::class,
            SendOrderWhatsAppNotification::class,
            SendOrderEmailNotification::class,
        ],
        OrderCreated::class => [
            InvalidateOrderReadCaches::class,
            NotifyTenantAdminsOfNewOrder::class,
            DeductOrderStock::class,
            RouteOrderToKitchenStations::class,
            PrintKitchenTicket::class,
            SendOrderWhatsAppNotification::class,
            SendOrderEmailNotification::class,
            UpdateCustomerExperience::class,
        ],
        OrderUpdated::class => [
            InvalidateOrderReadCaches::class,
            SyncUpdatedOrderStock::class,
        ],
        OrderPaid::class => [
            InvalidateOrderReadCaches::class,
            CreateOrderPaidInvoice::class,
            PrintKitchenTicket::class,
            SendOrderWhatsAppNotification::class,
            SendOrderEmailNotification::class,
        ],
        OrderMergeBillingPaid::class => [
            CreateOrderPaidInvoice::class,
        ],
    ];
}
