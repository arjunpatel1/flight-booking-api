<?php

namespace Modules\Cart;

use Illuminate\Contracts\Support\Arrayable;
use JsonException;
use JsonSerializable;

class CartOrderType implements JsonSerializable, Arrayable
{
    /**
     * Create a new instance of CartOrderType
     *
     * @param CartCondition|null $condition
     */
    public function __construct(protected ?CartCondition $condition)
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
            'id' => $this->value(),
            'name' => $this->name(),
        ];
    }

    public function value(): ?string
    {
        return $this->condition?->getValue();
    }

    public function name(): ?string
    {
        return $this->condition?->getName();
    }
}
