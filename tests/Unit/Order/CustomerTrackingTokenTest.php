<?php

namespace Tests\Unit\Order;

use Modules\Order\Support\CustomerTrackingToken;
use Tests\TestCase;

final class CustomerTrackingTokenTest extends TestCase
{
    public function test_token_is_tied_to_tenant_reference_and_expiry(): void
    {
        $tokens = app(CustomerTrackingToken::class);
        $token = $tokens->issue(20, 'ORD-SECURE', 1);

        $this->assertSame(20, $tokens->validate($token, 'ORD-SECURE'));
        $this->assertNull($tokens->validate($token, 'ORD-OTHER'));
        $this->assertNull($tokens->validate($token.'tampered', 'ORD-SECURE'));
    }
}
