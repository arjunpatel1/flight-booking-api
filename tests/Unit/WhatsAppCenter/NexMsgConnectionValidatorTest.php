<?php

namespace Tests\Unit\WhatsAppCenter;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Modules\WhatsAppCenter\Services\NexMsgConnectionValidator;
use Tests\TestCase;

class NexMsgConnectionValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('translator', new Translator(new ArrayLoader, 'en'));
        $this->app->forgetInstance('validator');
        ValidatorFacade::clearResolvedInstance('validator');
    }

    public function test_provider_ready_account_keeps_connection_eligible_for_save(): void
    {
        Http::fake([
            'api-nexmsg.myteknoland.com/api/accounts' => Http::response([$this->account()]),
        ]);

        app(NexMsgConnectionValidator::class)->validate($this->payload('6a475d96f6286cfd5aa377b8'));

        Http::assertSent(fn ($request) => $request->hasHeader('authkey', 'valid-auth-key'));
        $this->assertTrue(true);
    }

    public function test_unknown_account_id_is_rejected_before_active_connection_is_replaced(): void
    {
        Http::fake([
            'api-nexmsg.myteknoland.com/api/accounts' => Http::response([$this->account()]),
        ]);

        try {
            app(NexMsgConnectionValidator::class)->validate($this->payload('6aabc8ecd982a2bddbd1ccb6'));
            $this->fail('Expected invalid NexMsg account to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credentials.account_id', $exception->errors());
            $this->assertStringContainsString('does not belong', $exception->errors()['credentials.account_id'][0]);
        }
    }

    public function test_provider_auth_failure_returns_field_error(): void
    {
        Http::fake([
            'api-nexmsg.myteknoland.com/api/accounts' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        try {
            app(NexMsgConnectionValidator::class)->validate($this->payload('6a475d96f6286cfd5aa377b8'));
            $this->fail('Expected rejected auth key to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('rejected this Auth Key', $exception->errors()['credentials.account_id'][0]);
        }
    }

    public function test_mismatched_account_configuration_returns_actionable_field_errors(): void
    {
        Http::fake([
            'api-nexmsg.myteknoland.com/api/accounts' => Http::response([[
                ...$this->account(),
                'displayPhone' => '+91 92468 92524',
                'catalogId' => '',
                'nexdineOrdering' => ['enabled' => false, 'webhookUrl' => '', 'secretConfigured' => false],
            ]]),
        ]);

        try {
            app(NexMsgConnectionValidator::class)->validate($this->payload('6a475d96f6286cfd5aa377b8'));
            $this->fail('Expected incomplete ordering configuration to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('display_number', $exception->errors());
            $this->assertArrayHasKey('credentials.catalog_id', $exception->errors());
            $this->assertArrayHasKey('credentials.webhook_secret', $exception->errors());
        }
    }

    private function account(): array
    {
        return [
            'id' => '6a475d96f6286cfd5aa377b8',
            'wabaId' => '375690030937364',
            'displayPhone' => '+91 97419 57694',
            'catalogId' => '1613024323799809',
            'nexdineOrdering' => [
                'enabled' => true,
                'webhookUrl' => 'https://api.nexdine.myteknoland.in/v1/whatsapp/webhook/nexmsg',
                'secretConfigured' => true,
            ],
        ];
    }

    private function payload(string $accountId): array
    {
        return [
            'provider' => 'nexmsg',
            'provider_phone_id' => '375690030937364',
            'display_number' => '+91 97419 57694',
            'credentials' => [
                'account_id' => $accountId,
                'auth_key' => 'valid-auth-key',
                'catalog_id' => '1613024323799809',
            ],
        ];
    }
}
