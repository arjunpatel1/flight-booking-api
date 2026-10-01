<?php

namespace Tests\Unit\Order;

use Modules\Order\Delivery\DeliveryCommercialTerms;
use Tests\TestCase;

class DeliveryCommercialTermsTest extends TestCase
{
    public function test_wallet_credit_and_gst_are_derived_from_total_payable(): void
    {
        $amounts = DeliveryCommercialTerms::calculateWalletTopUpFromPayable(500, 18);

        $this->assertSame(423.73, $amounts['wallet_credit']);
        $this->assertSame(76.27, $amounts['gst_amount']);
        $this->assertSame(500.0, $amounts['gross_amount']);
        $this->assertSame(500.0, round($amounts['wallet_credit'] + $amounts['gst_amount'], 2));
    }
}
