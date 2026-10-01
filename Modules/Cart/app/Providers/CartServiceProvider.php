<?php

namespace Modules\Cart\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Cart\Cart;
use Modules\Cart\Storages\CartDBStorage;
use Modules\Cart\Http\Middleware\ValidateCartOwnership;

class CartServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->bind(Cart::class, function ($app) {
            $sessionKey = request()->route('cartId') ?: auth()->id();
            return new Cart(
                new CartDBStorage(),
                $app['events'],
                'cart',
                "cart_$sessionKey",
                config("cart.cart")
            );
        });

        $this->app->alias(Cart::class, 'cart');
    }

    /**
     * Boot the service provider.
     */
    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('validate_cart_ownership', ValidateCartOwnership::class);
    }
}
