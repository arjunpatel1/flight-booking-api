<?php

namespace Modules\Cart;

use Illuminate\Contracts\Support\Arrayable;
use JsonException;
use JsonSerializable;

class CartBranch implements JsonSerializable, Arrayable
{
    /**
     * Create a new instance of CartBranch
     *
     * @param CartCondition|null $condition
     */
    public function __construct(protected ?CartCondition $condition = null)
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
            'name' => $this->name(),
            'currency' => $this->currency()
        ];
    }

    public function id(): ?string
    {
        return $this->condition?->getValue();
    }

    public function name(): ?string
    {
        return $this->condition?->getName();
    }

    public function currency(): string
    {
        return $this->condition?->getAttribute('currency')
            ?: setting('default_currency')
            ?: config('app.currency', env('DEFAULT_CURRENCY', 'INR'));
    }
}
