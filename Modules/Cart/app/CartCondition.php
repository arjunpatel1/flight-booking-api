<?php

namespace Modules\Cart;

use Arr;
use Darryldecode\Cart\CartCondition as DarryldecodeCartCondition;

class CartCondition extends DarryldecodeCartCondition
{
    public function getAttribute($key, $default = null)
    {
        return Arr::get($this->getAttributes(), $key, $default);
    }
}
