<?php

namespace Modules\Aggregator\Tests\Unit;

use Modules\Aggregator\Services\PartnerApi\PartnerSignature;
use Tests\TestCase;

class PartnerSignatureTest extends TestCase
{
    public function test_it_builds_a_stable_canonical_signature_and_rejects_tampering(): void
    {
        $signer = new PartnerSignature;
        $signature = $signer->sign(
            'secret', 'post', '/api/v1/partner/orders', '1788422400',
            'nonce_0123456789', '{"external_order_id":"A-100"}',
        );

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
        $this->assertTrue($signer->verify(
            $signature, 'secret', 'POST', 'api/v1/partner/orders', '1788422400',
            'nonce_0123456789', '{"external_order_id":"A-100"}',
        ));
        $this->assertFalse($signer->verify(
            $signature, 'secret', 'POST', 'api/v1/partner/orders', '1788422400',
            'nonce_0123456789', '{"external_order_id":"A-101"}',
        ));
    }

    public function test_menu_product_pagination_query_is_part_of_the_signature(): void
    {
        $signer = new PartnerSignature;
        $secret = 'secret';
        $timestamp = '1788422400';
        $nonce = 'nonce_0123456789';
        $target = '/v1/partner/menus/menu-uuid/products?page=4&per_page=100';

        $signature = $signer->sign($secret, 'GET', $target, $timestamp, $nonce, '');

        $this->assertTrue($signer->verify($signature, $secret, 'GET', '/v1/partner/menus/menu-uuid/products?per_page=100&page=4', $timestamp, $nonce, ''));
        $this->assertFalse($signer->verify($signature, $secret, 'GET', '/v1/partner/menus/menu-uuid/products', $timestamp, $nonce, ''));
    }

    public function test_query_parameters_are_sorted_and_covered_by_the_signature(): void
    {
        $signer = new PartnerSignature;
        $first = $signer->sign('secret', 'GET', '/api/v1/partner/orders?per_page=25&page=2', '1788422400', 'nonce_0123456789', '');
        $reordered = $signer->sign('secret', 'GET', '/api/v1/partner/orders?page=2&per_page=25', '1788422400', 'nonce_0123456789', '');
        $tampered = $signer->sign('secret', 'GET', '/api/v1/partner/orders?page=3&per_page=25', '1788422400', 'nonce_0123456789', '');

        $this->assertSame($first, $reordered);
        $this->assertNotSame($first, $tampered);
        $this->assertSame('/orders?a=1&b=two%20words', $signer->canonicalTarget('/orders?b=two%20words&a=1'));
    }
}
