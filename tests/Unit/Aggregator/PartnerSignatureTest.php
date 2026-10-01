<?php

namespace Tests\Unit\Aggregator;

use Modules\Aggregator\Services\PartnerApi\PartnerSignature;
use PHPUnit\Framework\TestCase;

class PartnerSignatureTest extends TestCase
{
    public function test_v2_signature_binds_and_normalizes_query_parameters(): void
    {
        $signatures = new PartnerSignature;
        $first = $signatures->sign('secret', 'GET', '/v1/partner/orders?page=2&status=ready', '123', 'nonce-1234567890', '');
        $reordered = $signatures->sign('secret', 'GET', '/v1/partner/orders?status=ready&page=2', '123', 'nonce-1234567890', '');
        $changed = $signatures->sign('secret', 'GET', '/v1/partner/orders?page=3&status=ready', '123', 'nonce-1234567890', '');

        $this->assertSame($first, $reordered);
        $this->assertNotSame($first, $changed);
    }

    public function test_v1_preserves_existing_path_only_signatures_during_rotation(): void
    {
        $signatures = new PartnerSignature;
        $first = $signatures->sign('secret', 'GET', '/v1/partner/orders?page=2', '123', 'nonce-1234567890', '', 1);
        $changedQuery = $signatures->sign('secret', 'GET', '/v1/partner/orders?page=3', '123', 'nonce-1234567890', '', 1);

        $this->assertSame($first, $changedQuery);
    }
}
