<?php

namespace Tests\Unit\Payment;

use Illuminate\Http\Request;
use Modules\Payment\Services\DirectUpiWebhookVerifier;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DirectUpiWebhookVerifierTest extends TestCase
{
    public function test_it_accepts_the_exact_timestamp_and_raw_body_signature(): void
    {
        $body = '{"id":"evt_123","type":"payment.verified"}';
        $timestamp = now()->toIso8601String();
        $secret = str_repeat('s', 32);
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ], $body);

        $this->assertSame($body, app(DirectUpiWebhookVerifier::class)->verify($request, $secret));
    }

    public function test_it_rejects_a_signature_created_with_a_different_timestamp(): void
    {
        $body = '{"id":"evt_123"}';
        $headerTimestamp = now()->toIso8601String();
        $signedTimestamp = now()->subSecond()->toIso8601String();
        $secret = str_repeat('s', 32);
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_WEBHOOK_TIMESTAMP' => $headerTimestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $signedTimestamp.'.'.$body, $secret),
        ], $body);

        $this->expectException(HttpException::class);
        app(DirectUpiWebhookVerifier::class)->verify($request, $secret);
    }

    public function test_it_rejects_stale_deliveries(): void
    {
        $body = '{"id":"evt_123"}';
        $timestamp = now()->subMinutes(6)->toIso8601String();
        $secret = str_repeat('s', 32);
        $request = Request::create('/webhook', 'POST', [], [], [], [
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ], $body);

        $this->expectException(HttpException::class);
        app(DirectUpiWebhookVerifier::class)->verify($request, $secret);
    }
}
