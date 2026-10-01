<?php

namespace Tests\Feature\WhatsAppCenter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;
use Modules\WhatsAppCenter\Http\Controllers\Api\V1\WhatsAppOrderingController;
use Modules\WhatsAppCenter\Jobs\ProcessWhatsAppOrderingMessage;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WhatsAppWebhookReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'certification-secret-123';

    public static function providers(): array
    {
        return [['meta'], ['msg91']];
    }

    #[DataProvider('providers')]
    public function test_shared_provider_webhook_resolves_profile_from_phone_and_dispatches(string $provider): void
    {
        Queue::fake();
        $context = $this->context($provider);

        $response = $this->submitShared($provider, $this->payload($provider, 'shared-event-'.$provider));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('whatsapp_webhook_events', [
            'tenant_id' => $context['tenant']->id,
            'provider_profile_id' => $context['profile']->id,
            'provider_event_id' => 'shared-event-'.$provider,
        ]);
        Queue::assertPushed(ProcessWhatsAppOrderingMessage::class, 1);
    }

    public function test_meta_shared_challenge_uses_platform_verify_token(): void
    {
        config()->set('whatsappcenter.webhooks.meta_verify_token', 'platform-verify-token');
        $request = Request::create('/webhook/meta', 'GET', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'platform-verify-token',
            'hub_challenge' => 'challenge-value',
        ]);

        $response = app(WhatsAppOrderingController::class)->verifyProviderWebhook($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('challenge-value', $response->getContent());
    }

    #[DataProvider('providers')]
    public function test_shared_provider_webhook_fails_closed_for_unknown_phone(string $provider): void
    {
        $this->context($provider);
        $response = $this->submitShared(
            $provider,
            $this->payload($provider, 'unknown-shared-'.$provider, phone: 'not-configured'),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('WHATSAPP_WEBHOOK_REJECTED', $response->getData(true)['errors']['code']);
        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
    }

    #[DataProvider('providers')]
    public function test_shared_provider_webhook_rejects_invalid_signature_after_safe_lookup(string $provider): void
    {
        $this->context($provider);

        try {
            $this->submitShared($provider, $this->payload($provider, 'bad-signature-'.$provider), 'sha256=invalid');
            $this->fail('Invalid shared-webhook signature was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
    }

    public function test_shared_provider_webhook_rejects_ambiguous_phone_mapping(): void
    {
        $first = $this->context('meta');
        $second = $this->context('meta');
        $second['number']->update(['provider_phone_id' => $first['number']->provider_phone_id]);

        $response = $this->submitShared('meta', $this->payload('meta', 'ambiguous-phone'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('WHATSAPP_WEBHOOK_REJECTED', $response->getData(true)['errors']['code']);
        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
    }

    #[DataProvider('providers')]
    public function test_valid_webhook_is_persisted_and_dispatched_once(string $provider): void
    {
        Queue::fake();
        $context = $this->context($provider);

        $response = $this->submit($context, $this->payload($provider, 'event-1'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('whatsapp_webhook_events', [
            'tenant_id' => $context['tenant']->id, 'provider_profile_id' => $context['profile']->id,
            'provider_event_id' => 'event-1', 'status' => 'queued',
        ]);
        $this->assertDatabaseCount('whatsapp_conversations', 1);
        $this->assertDatabaseCount('whatsapp_messages', 1);
        Queue::assertPushed(ProcessWhatsAppOrderingMessage::class, 1);
    }

    #[DataProvider('providers')]
    public function test_invalid_and_missing_signatures_are_rejected(string $provider): void
    {
        $context = $this->context($provider);
        foreach (['sha256=invalid', null] as $signature) {
            try {
                $this->submit($context, $this->payload($provider, (string) Str::uuid()), $signature);
                $this->fail('Invalid signature was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(401, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
    }

    #[DataProvider('providers')]
    public function test_duplicate_is_idempotent_and_conflicting_replay_is_rejected(string $provider): void
    {
        Queue::fake();
        $context = $this->context($provider);
        $original = $this->payload($provider, 'event-replay', 'MENU');
        $this->submit($context, $original);
        $this->submit($context, $original);

        $this->assertDatabaseCount('whatsapp_webhook_events', 1);
        $this->assertDatabaseCount('whatsapp_messages', 1);
        Queue::assertPushed(ProcessWhatsAppOrderingMessage::class, 1);

        try {
            $this->submit($context, $this->payload($provider, 'event-replay', 'CART'));
            $this->fail('Conflicting replay was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('whatsapp_webhook_events', 1);
    }

    #[DataProvider('providers')]
    public function test_expired_provider_event_is_rejected_as_replay(string $provider): void
    {
        $context = $this->context($provider);
        $payload = $this->payload($provider, 'event-expired');
        if ($provider === 'meta') {
            $payload['entry'][0]['changes'][0]['value']['messages'][0]['timestamp'] = (string) now()->subMinutes(6)->timestamp;
        } else {
            $payload['timestamp'] = (string) now()->subMinutes(6)->timestamp;
        }

        try {
            $this->submit($context, $payload);
            $this->fail('Expired event was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
    }

    #[DataProvider('providers')]
    public function test_malformed_missing_id_and_unsupported_events_fail_closed(string $provider): void
    {
        $context = $this->context($provider);
        foreach ([
            [],
            $this->payload($provider, '', 'MENU'),
            $this->payload($provider, 'event-image', 'MENU', 'image'),
        ] as $payload) {
            try {
                $this->submit($context, $payload);
                $this->fail('Invalid event was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
    }

    #[DataProvider('providers')]
    public function test_unknown_account_and_unknown_number_fail_without_persistence(string $provider): void
    {
        $context = $this->context($provider);
        $unknownProfile = $context;
        $unknownProfile['profile_key'] = (string) Str::uuid();
        $this->assertSame(404, $this->submit($unknownProfile, $this->payload($provider, 'event-profile'))->getStatusCode());

        $this->assertSame(404, $this->submit(
            $context,
            $this->payload($provider, 'event-phone', 'MENU', 'text', 'unknown-phone'),
        )->getStatusCode());
        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
    }

    #[DataProvider('providers')]
    public function test_disabled_profile_tenant_and_branch_fail_closed(string $provider): void
    {
        Queue::fake();
        foreach (['profile', 'tenant', 'branch'] as $disabled) {
            $context = $this->context($provider, $disabled);
            $this->assertSame(404, $this->submit(
                $context,
                $this->payload($provider, 'event-disabled-'.$disabled),
            )->getStatusCode());
        }
        // Fail closed: nothing is queued or processed. A signed rejection is
        // kept only as a failed diagnostic entry for the number's owner.
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('whatsapp_webhook_events', ['status' => 'queued']);
        $this->assertDatabaseMissing('whatsapp_webhook_events', ['status' => 'processed']);
        $this->assertDatabaseCount('whatsapp_conversations', 0);
        $this->assertDatabaseHas('whatsapp_webhook_events', [
            'provider_event_id' => 'rejected:event-disabled-branch', 'status' => 'failed', 'event_type' => 'rejected',
        ]);
    }

    #[DataProvider('providers')]
    public function test_suspended_assignment_rejection_is_visible_to_the_restaurant(string $provider): void
    {
        Queue::fake();
        $context = $this->context($provider);
        $context['assignment']->update(['is_active' => false, 'suspended_at' => now()]);

        $this->assertSame(404, $this->submit($context, $this->payload($provider, 'event-suspended'))->getStatusCode());

        Queue::assertNothingPushed();
        $event = \Modules\WhatsAppCenter\Models\WhatsAppWebhookEvent::query()->withoutGlobalTenant()->sole();
        $this->assertSame('failed', $event->status);
        $this->assertSame((int) $context['tenant']->id, (int) $event->tenant_id);
        $this->assertStringContainsString('not assigned to an active restaurant', (string) $event->error);
        $this->assertStringContainsString('not assigned to an active restaurant', (string) $context['profile']->fresh()->last_error);

        // Once the admin fixes the assignment, the provider's retry of the
        // same event is processed normally instead of being treated as a replay.
        $context['assignment']->update(['is_active' => true, 'suspended_at' => null]);
        $this->assertSame(200, $this->submit($context, $this->payload($provider, 'event-suspended'))->getStatusCode());
        Queue::assertPushed(ProcessWhatsAppOrderingMessage::class, 1);
        $this->assertNull($context['profile']->fresh()->last_error);
    }

    #[DataProvider('providers')]
    public function test_invalid_signature_is_remembered_on_the_profile_without_an_event(string $provider): void
    {
        $context = $this->context($provider);
        try {
            $this->submit($context, $this->payload($provider, 'event-bad-signature'), 'sha256='.str_repeat('0', 64));
            $this->fail('Expected an invalid signature to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('whatsapp_webhook_events', 0);
        $this->assertStringContainsString('invalid signature', (string) $context['profile']->fresh()->last_error);
    }

    #[DataProvider('providers')]
    public function test_wrong_tenant_and_branch_mapping_cannot_be_selected_by_payload(string $provider): void
    {
        Queue::fake();
        $foreignTenantIds = [];
        foreach (['tenant', 'branch'] as $mismatch) {
            $context = $this->context($provider);
            $foreign = Tenant::query()->create([
                'name' => 'Foreign '.$mismatch, 'slug' => 'foreign-'.$mismatch.'-'.Str::lower(Str::random(6)), 'is_active' => true,
            ]);
            $foreignTenantIds[] = $foreign->id;
            $foreignBranch = Branch::factory()->create(['tenant_id' => $foreign->id, 'is_active' => true, 'is_accepting_orders' => true]);
            if ($mismatch === 'tenant') {
                $context['profile']->update(['tenant_id' => $foreign->id]);
            } else {
                $context['assignment']->update(['allowed_branch_ids' => [$foreignBranch->id]]);
            }
            $payload = $this->payload($provider, 'event-mismatch-'.$mismatch);
            $payload['tenant_id'] = $foreign->id;
            $payload['branch_id'] = $foreignBranch->id;
            $this->assertSame(404, $this->submit($context, $payload)->getStatusCode());
        }
        // The payload can never select a tenant: nothing is written for the
        // foreign restaurant and nothing is queued or processed.
        Queue::assertNothingPushed();
        $this->assertSame(0, \Modules\WhatsAppCenter\Models\WhatsAppWebhookEvent::query()->withoutGlobalTenant()
            ->whereIn('tenant_id', $foreignTenantIds)->count());
        $this->assertDatabaseMissing('whatsapp_webhook_events', ['status' => 'queued']);
        $this->assertDatabaseMissing('whatsapp_webhook_events', ['status' => 'processed']);
        $this->assertDatabaseCount('whatsapp_conversations', 0);
    }

    private function context(string $provider, ?string $disabled = null): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Webhook '.$provider, 'slug' => 'webhook-'.$provider.'-'.Str::lower(Str::random(8)),
            'is_active' => $disabled !== 'tenant',
        ]);
        $branch = Branch::factory()->create([
            'tenant_id' => $tenant->id, 'is_active' => $disabled !== 'branch', 'is_accepting_orders' => true,
        ]);
        $profile = WhatsAppProviderProfile::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'name' => ucfirst($provider), 'ownership_mode' => 'restaurant_owned',
            'provider' => $provider, 'credentials' => ['webhook_secret' => self::SECRET],
            'status' => 'connected', 'is_active' => $disabled !== 'profile',
        ]);
        $number = WhatsAppPhoneNumber::query()->create([
            'provider_profile_id' => $profile->id, 'provider_phone_id' => 'phone-'.$provider,
            'display_number' => '+910000000001', 'status' => 'connected', 'is_active' => true,
        ]);
        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'provider_profile_id' => $profile->id, 'phone_number_id' => $number->id,
            'ownership_mode' => 'restaurant_owned', 'allowed_branch_ids' => [$branch->id],
            'capabilities' => ['ordering' => true], 'is_active' => true,
        ]);

        return compact('tenant', 'branch', 'profile', 'number', 'assignment') + ['profile_key' => $profile->uuid];
    }

    private function payload(string $provider, string $eventId, string $text = 'MENU', string $type = 'text', ?string $phone = null): array
    {
        $phone ??= 'phone-'.$provider;
        if ($provider === 'meta') {
            return ['entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => $phone],
                'messages' => [['id' => $eventId, 'from' => '919999999999', 'type' => $type, 'text' => ['body' => $text], 'timestamp' => (string) time()]],
            ]]]]]];
        }

        return ['integrated_number' => $phone, 'event_id' => $eventId, 'from' => '919999999999',
            'type' => $type, 'text' => $text, 'timestamp' => (string) time()];
    }

    private function submit(array $context, array $payload, string|null|false $signature = false)
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $signature === false ? 'sha256='.hash_hmac('sha256', $raw, self::SECRET) : $signature;
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_WEBHOOK_SIGNATURE'] = $signature;
        }
        $request = Request::create('/webhook', 'POST', [], [], [], $server, $raw);

        return app(WhatsAppOrderingController::class)->webhook($request, $context['profile_key']);
    }

    private function submitShared(string $provider, array $payload, string|null|false $signature = false)
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = $signature === false ? 'sha256='.hash_hmac('sha256', $raw, self::SECRET) : $signature;
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_WEBHOOK_SIGNATURE'] = $signature;
        }
        $request = Request::create('/webhook/'.$provider, 'POST', [], [], [], $server, $raw);

        return app(WhatsAppOrderingController::class)->providerWebhook($request, $provider);
    }
}
