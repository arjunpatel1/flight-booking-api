<?php

namespace Modules\Cart;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Modules\User\Models\User;

class CartCustomer implements Arrayable, JsonSerializable
{
    /**
     * Create a new instance of CartCustomer
     *
     * @param CartCondition $condition
     */
    public function __construct(protected CartCondition $condition)
    {
    }

    public function __toString()
    {
        return json_encode($this->jsonSerialize());
    }

    /** @inheritDoc */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'name' => $this->name(),
        ];
    }

    /**
     * Get customer id
     *
     * @return int
     */
    public function id(): int
    {
        return $this->condition->getAttribute('customer')->id;
    }

    /**
     * Get customer name
     *
     * @return string
     */
    public function name(): string
    {
        /** @var User $customer */
        $customer = $this->condition->getAttribute('customer');
        return "$customer->name ($customer->phone)";
    }
}
