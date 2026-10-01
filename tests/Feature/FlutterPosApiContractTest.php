<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Modules\Core\Http\Middleware\EnsureIdempotentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FlutterPosApiContractTest extends TestCase
{
    #[DataProvider('flutterEndpointProvider')]
    public function test_flutter_pos_endpoint_is_registered(string $method, string $uri): void
    {
        $this->assertNotNull(
            $this->findRoute($method, $uri),
            "{$method} {$uri} is missing from the backend route table."
        );
    }

    #[DataProvider('criticalMutationProvider')]
    public function test_critical_mutation_requires_idempotency_key(string $method, string $uri): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, "{$method} {$uri} is missing from the backend route table.");

        $middleware = implode('|', $route->gatherMiddleware());

        $this->assertStringContainsString(EnsureIdempotentRequest::class, $middleware);
        $this->assertStringContainsString(EnsureIdempotentRequest::class . ':required', $middleware);
    }

    #[DataProvider('waiterPrinterEndpointProvider')]
    public function test_waiter_print_permission_can_access_printer_management(string $method, string $uri): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, "{$method} {$uri} is missing from the backend route table.");
        $this->assertStringContainsString(
            'admin.orders.print',
            implode('|', $route->gatherMiddleware()),
            "{$method} {$uri} must remain accessible to waiter/cashier printing roles."
        );
    }

    #[DataProvider('staticOrderEndpointProvider')]
    public function test_static_order_endpoints_do_not_fall_through_to_dynamic_order_routes(
        string $method,
        string $uri,
        string $expectedAction
    ): void {
        $route = RouteFacade::getRoutes()->match(Request::create($uri, $method));

        $this->assertSame(
            $expectedAction,
            $route->getActionMethod(),
            "{$method} {$uri} must resolve to {$expectedAction}, not a dynamic order-id route."
        );
    }

    public static function flutterEndpointProvider(): array
    {
        return [
            'login' => ['POST', 'api/v1/auth/login'],
            'logout' => ['POST', 'api/v1/auth/logout'],
            'refresh' => ['POST', 'api/v1/auth/refresh'],
            'me' => ['GET', 'api/v1/accounts/me'],
            'settings' => ['GET', 'api/v1/app/settings'],
            'translations' => ['GET', 'api/v1/app/translations'],
            'tables' => ['GET', 'api/v1/tables/viewer'],
            'table detail' => ['GET', 'api/v1/tables/viewer/{id}'],
            'table merge' => ['POST', 'api/v1/tables/viewer/{id}/merge'],
            'table transfer' => ['PATCH', 'api/v1/tables/viewer/{id}/transfer'],
            'table split' => ['POST', 'api/v1/tables/viewer/{id}/split'],
            'table assign waiter' => ['PATCH', 'api/v1/tables/viewer/{id}/assign-waiter'],
            'table reservations' => ['GET', 'api/v1/tables/{id}/reservations'],
            'reservations' => ['GET', 'api/v1/reservations'],
            'upcoming reservations' => ['GET', 'api/v1/reservations/upcoming'],
            'reservation meta' => ['GET', 'api/v1/reservations/meta'],
            'reservation confirm' => ['POST', 'api/v1/reservations/{id}/confirm'],
            'reservation seat' => ['POST', 'api/v1/reservations/{id}/seat'],
            'reservation cancel' => ['POST', 'api/v1/reservations/{id}/cancel'],
            'cart show' => ['GET', 'api/v1/cart/{cartId}'],
            'cart clear' => ['DELETE', 'api/v1/cart/{cartId}/clear'],
            'cart items' => ['POST', 'api/v1/cart/{cartId}/items'],
            'cart batch' => ['POST', 'api/v1/cart/{cartId}/items/batch'],
            'cart quick' => ['POST', 'api/v1/cart/{cartId}/items/quick'],
            'cart item update' => ['PUT', 'api/v1/cart/{cartId}/items/{itemId}'],
            'cart item delete' => ['DELETE', 'api/v1/cart/{cartId}/items/{itemId}'],
            'cart item action' => ['POST', 'api/v1/cart/{cartId}/items/{itemId}/action'],
            'cart order type' => ['POST', 'api/v1/cart/{cartId}/order-types/{type}'],
            'cart order type remove' => ['DELETE', 'api/v1/cart/{cartId}/order-types'],
            'cart discount' => ['POST', 'api/v1/cart/{cartId}/discounts/{id}'],
            'cart customer' => ['POST', 'api/v1/cart/{cartId}/customers/{id}'],
            'cart voucher' => ['POST', 'api/v1/cart/{cartId}/vouchers'],
            'orders active' => ['GET', 'api/v1/orders/active'],
            'orders upcoming' => ['GET', 'api/v1/orders/upcoming'],
            'orders list' => ['GET', 'api/v1/orders'],
            'order create' => ['POST', 'api/v1/orders/{cartId}'],
            'order detail' => ['GET', 'api/v1/orders/{orderId}/show'],
            'order update' => ['PUT', 'api/v1/orders/{cartId}/{orderId}/update'],
            'order cancel' => ['POST', 'api/v1/orders/{orderId}/cancel'],
            'order refund' => ['POST', 'api/v1/orders/{orderId}/refund'],
            'order move status' => ['PATCH', 'api/v1/orders/{orderId}/move-to-next-status'],
            'order finalize kot' => ['POST', 'api/v1/orders/{orderId}/finalize-kot'],
            'order reprint bill' => ['POST', 'api/v1/orders/{orderId}/reprint-bill'],
            'order print meta' => ['GET', 'api/v1/orders/{orderId}/print'],
            'order print direct' => ['POST', 'api/v1/orders/{orderId}/print/{type}'],
            'order print preview' => ['GET', 'api/v1/orders/{orderId}/print/{type}/preview'],
            'order payment' => ['POST', 'api/v1/orders/{orderId}/payments'],
            'order payment meta' => ['GET', 'api/v1/orders/{orderId}/payments/meta'],
            'payments process' => ['POST', 'api/v1/payments/process'],
            'payments refund' => ['POST', 'api/v1/payments/refund'],
            'pos configuration' => ['GET', 'api/v1/pos/viewer/{cartId}/configuration'],
            'pos menu items default' => ['GET', 'api/v1/pos/viewer/{cartId}/menu-items'],
            'pos menu items for menu' => ['GET', 'api/v1/pos/viewer/{cartId}/menu-items/{menuId}'],
            'pos waiter dashboard' => ['GET', 'api/v1/pos/waiter-dashboard'],
            'pos waiter assistant' => ['GET', 'api/v1/pos/waiter-assistant'],
            'pos automation' => ['GET', 'api/v1/pos/automation'],
            'pos owner command center' => ['GET', 'api/v1/pos/owner-command-center'],
            'pos performance intelligence' => ['GET', 'api/v1/pos/performance-intelligence'],
            'pos revenue intelligence' => ['GET', 'api/v1/pos/revenue-intelligence'],
            'pos cart waiter dashboard' => ['GET', 'api/v1/pos/viewer/{cartId}/waiter-dashboard'],
            'pos recovery dashboard' => ['GET', 'api/v1/pos/recovery-dashboard'],
            'pos sessions' => ['GET', 'api/v1/pos/sessions'],
            'pos session open' => ['POST', 'api/v1/pos/sessions/open'],
            'pos session close' => ['PUT', 'api/v1/pos/sessions/{id}/close'],
            'pos registers' => ['GET', 'api/v1/pos/registers'],
            'terminal heartbeat' => ['POST', 'api/v1/pos/terminal-devices/heartbeat'],
            'kitchen orders' => ['GET', 'api/v1/pos/kitchen-viewer/orders'],
            'kitchen move status' => ['PATCH', 'api/v1/pos/kitchen-viewer/{orderId}/move-to-next-status'],
            'kitchen stations' => ['GET', 'api/v1/pos/kitchen-stations'],
            'offline status' => ['GET', 'api/v1/offline-mode/status'],
            'offline sync' => ['POST', 'api/v1/offline-mode/sync'],
            'offline orders list' => ['GET', 'api/v1/offline-mode/orders'],
            'offline order create' => ['POST', 'api/v1/offline-mode/orders'],
            'printers' => ['GET', 'api/v1/printers'],
            'printer test print' => ['POST', 'api/v1/printers/{id}/test-print'],
            'print jobs' => ['GET', 'api/v1/print-jobs'],
            'print job summary' => ['GET', 'api/v1/print-jobs/summary'],
            'print job diagnostics' => ['GET', 'api/v1/print-jobs/diagnostics'],
            'print job retry' => ['POST', 'api/v1/print-jobs/{id}/retry'],
        ];
    }

    public static function criticalMutationProvider(): array
    {
        return [
            'order create' => ['POST', 'api/v1/orders/{cartId}'],
            'order update' => ['PUT', 'api/v1/orders/{cartId}/{orderId}/update'],
            'order cancel' => ['POST', 'api/v1/orders/{orderId}/cancel'],
            'order refund' => ['POST', 'api/v1/orders/{orderId}/refund'],
            'order move status' => ['PATCH', 'api/v1/orders/{orderId}/move-to-next-status'],
            'order finalize kot' => ['POST', 'api/v1/orders/{orderId}/finalize-kot'],
            'order print' => ['POST', 'api/v1/orders/{orderId}/print/{type}'],
            'order payment' => ['POST', 'api/v1/orders/{orderId}/payments'],
            'payment process' => ['POST', 'api/v1/payments/process'],
            'payment refund' => ['POST', 'api/v1/payments/refund'],
        ];
    }

    public static function waiterPrinterEndpointProvider(): array
    {
        return [
            'printers' => ['GET', 'api/v1/printers'],
            'printer test print' => ['POST', 'api/v1/printers/{id}/test-print'],
            'print jobs' => ['GET', 'api/v1/print-jobs'],
            'print job summary' => ['GET', 'api/v1/print-jobs/summary'],
            'print job diagnostics' => ['GET', 'api/v1/print-jobs/diagnostics'],
            'print job retry' => ['POST', 'api/v1/print-jobs/{id}/retry'],
        ];
    }

    public static function staticOrderEndpointProvider(): array
    {
        return [
            'active orders' => ['GET', 'api/v1/orders/active', 'activeOrders'],
            'upcoming orders' => ['GET', 'api/v1/orders/upcoming', 'upcomingOrders'],
            'order stats' => ['GET', 'api/v1/orders/stats', 'stats'],
            'my orders' => ['GET', 'api/v1/orders/my-orders', 'customerOrders'],
        ];
    }

    private function findRoute(string $method, string $uri): ?Route
    {
        $method = strtoupper($method);
        $uri = trim($uri, '/');

        foreach (RouteFacade::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
