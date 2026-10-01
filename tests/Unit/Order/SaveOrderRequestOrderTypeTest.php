<?php

namespace Tests\Unit\Order;

use Modules\Order\Http\Requests\Api\V1\SaveOrderRequest;
use Tests\TestCase;

class SaveOrderRequestOrderTypeTest extends TestCase
{
    public function test_validation_keeps_the_explicit_checkout_order_type(): void
    {
        $request = SaveOrderRequest::create('/v1/orders', 'POST', [
            'type' => 'dine_in',
        ]);

        $this->assertSame('dine_in', $request->validationData()['type']);
    }
}
