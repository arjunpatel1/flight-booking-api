<?php

namespace Tests\Unit\Notification;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Notification\Services\MailgunWebhookVerifier;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MailgunWebhookVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'services.mailgun.webhook_signing_key' => str_repeat('m', 32)]);
        Cache::flush();
    }

    public function test_it_accepts_a_valid_signature_and_rejects_replay(): void
    {
        $request = $this->request(now()->timestamp, 'unique-token');
        $verifier = app(MailgunWebhookVerifier::class);
        $verifier->verify($request, 'test');
        $this->addToAssertionCount(1);

        $this->expectException(HttpException::class);
        $verifier->verify($request, 'test');
    }

    public function test_it_rejects_a_stale_signature(): void
    {
        $this->expectException(HttpException::class);
        app(MailgunWebhookVerifier::class)->verify($this->request(now()->subMinutes(6)->timestamp, 'stale-token'), 'test');
    }

    private function request(int $timestamp, string $token): Request
    {
        $signature = hash_hmac('sha256', $timestamp.$token, (string) config('services.mailgun.webhook_signing_key'));

        return Request::create('/mailgun', 'POST', [
            'timestamp' => (string) $timestamp,
            'token' => $token,
            'signature' => $signature,
        ]);
    }
}
