<?php

namespace Modules\Cart;

use Illuminate\Contracts\Support\Arrayable;
use JsonException;
use JsonSerializable;

class CartLoyaltyGift implements JsonSerializable, Arrayable
{
    /**
     * Create a new instance of CartLoyaltyGift
     *
     * @param array $gift
     */
    public function __construct(protected array $gift)
    {

    }

    /**
     * @throws JsonException
     */
    public function __toString(): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR);
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
        ];
    }

    public function id(): int
    {
        return $this->gift['id'];
    }
}
