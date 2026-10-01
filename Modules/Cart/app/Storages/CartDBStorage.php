<?php

namespace Modules\Cart\Storages;

use Darryldecode\Cart\CartCollection;
use Modules\Cart\Models\Cart;

class CartDBStorage
{
    /**
     * Request-local cache for repeated reads of the same cart storage rows.
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * @var array<string, bool>
     */
    private array $exists = [];

    /**
     * Retrieve the cart data for a given key.
     *
     * @param string $key
     * @return CartCollection|array
     */
    public function get(string $key): CartCollection|array
    {
        if (array_key_exists($key, $this->cache)) {
            return empty($this->cache[$key])
                ? []
                : new CartCollection(items: $this->cache[$key]);
        }

        $cart = Cart::query()->find($key);

        if (!$cart || empty($cart->data)) {
            $this->exists[$key] = (bool) $cart;
            $this->cache[$key] = [];

            return [];
        }

        $this->exists[$key] = true;
        $this->cache[$key] = $cart->data;

        return new CartCollection(items: $cart->data);
    }

    /**
     * Determine if a cart exists for the given key.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        if (array_key_exists($key, $this->exists)) {
            return $this->exists[$key];
        }

        return $this->exists[$key] = Cart::query()->whereKey($key)->exists();
    }

    /**
     * Store or update the cart data for a given key.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function put(string $key, mixed $value): void
    {
        Cart::query()
            ->updateOrCreate(
                ['id' => $key],
                ['data' => $value]
            );

        $this->cache[$key] = $value;
        $this->exists[$key] = true;
    }
}
