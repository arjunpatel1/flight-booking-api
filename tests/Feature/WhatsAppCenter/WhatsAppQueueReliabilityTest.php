<?php

namespace Tests\Feature\WhatsAppCenter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;
use Modules\WhatsAppCenter\Jobs\ProcessWhatsAppOrderingMessage;
use Modules\WhatsAppCenter\Models\WhatsAppConversation;
use Modules\WhatsAppCenter\Models\WhatsAppMessage;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use Modules\WhatsAppCenter\Models\WhatsAppWebhookEvent;
use Modules\WhatsAppCenter\Services\WhatsAppChannelContextResolver;
use Modules\WhatsAppCenter\Services\WhatsAppOrderingEngine;
use Modules\WhatsAppCenter\Services\WhatsAppOrderingSender;
use Modules\WhatsAppCenter\Transformers\Api\V1\WhatsAppMessageResource;
use RuntimeException;
use Tests\TestCase;

class WhatsAppQueueReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_success_persists_provider_message_id_and_duplicate_job_is_a_noop(): void
    {
        $records = $this->records();
        $engine = $this->mock(WhatsAppOrderingEngine::class);
        $sender = $this->mock(WhatsAppOrderingSender::class);
        $engine->shouldReceive('handle')->once()->andReturn('Welcome');
        $sender->shouldReceive('sendText')->once()->andReturn(['status' => 200, 'provider_message_id' => 'provider-123']);

        $job = $this->job($records);
        $job->handle($engine, $sender, app(WhatsAppChannelContextResolver::class));
        $job->handle($engine, $sender, app(WhatsAppChannelContextResolver::class));

        $this->assertDatabaseCount('whatsapp_messages', 2);
        $this->assertDatabaseHas('whatsapp_messages', [
            'direction' => 'outbound', 'status' => 'sent', 'provider_message_id' => 'provider-123',
        ]);
        $this->assertSame(1, data_get(WhatsAppMessage::query()->where('direction', 'outbound')->first()->payload, 'attempts'));
        $resource = (new WhatsAppMessageResource(WhatsAppMessage::query()->where('direction', 'outbound')->first()))->resolve(request());
        $this->assertSame('provider-123', $resource['provider_message_id']);
        $this->assertSame(0, $resource['retry_count']);
        $this->assertArrayNotHasKey('payload', $resource);
        $this->assertSame('processed', $records['event']->refresh()->status);
        $this->assertStringNotContainsString('queue-test-secret', serialize($job));
    }

    public function test_provider_failure_reuses_outbound_record_then_retry_succeeds(): void
    {
        $records = $this->records();
        $engine = $this->mock(WhatsAppOrderingEngine::class);
        $sender = $this->mock(WhatsAppOrderingSender::class);
        $engine->shouldReceive('handle')->twice()->andReturn('Retry reply');
        $attempt = 0;
        $sender->shouldReceive('sendText')->twice()->andReturnUsing(function () use (&$attempt) {
            if (++$attempt === 1) {
                throw new RuntimeException('Controlled provider failure');
            }
            return ['status' => 200, 'provider_message_id' => 'provider-retry'];
        });
        $job = $this->job($records);

        try {
            $job->handle($engine, $sender, app(WhatsAppChannelContextResolver::class));
            $this->fail('Controlled failure did not escape for queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled provider failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('whatsapp_messages', ['direction' => 'outbound', 'status' => 'failed']);

        $job->handle($engine, $sender, app(WhatsAppChannelContextResolver::class));
        $outbound = WhatsAppMessage::query()->where('direction', 'outbound')->sole();
        $this->assertSame('sent', $outbound->status);
        $this->assertSame('provider-retry', $outbound->provider_message_id);
        $this->assertSame(2, data_get($outbound->payload, 'attempts'));
        $this->assertDatabaseCount('whatsapp_messages', 2);
    }

    public function test_exhausted_failure_callback_is_safe_and_observable(): void
    {
        $records = $this->records();
        $job = $this->job($records);
        $job->failed(new RuntimeException('secret provider diagnostic'));

        $event = $records['event']->refresh();
        $this->assertSame('failed', $event->status);
        $this->assertSame('Processing failed.', $event->error);
        $this->assertNotNull($event->processed_at);
        $this->assertStringNotContainsString('secret provider diagnostic', (string) $event->error);
    }

    public function test_stale_tenant_branch_profile_and_assignment_contexts_fail_without_outbound(): void
    {
        foreach (['tenant', 'branch', 'profile', 'assignment'] as $disabled) {
            $records = $this->records();
            $records[$disabled]->update($disabled === 'assignment'
                ? ['is_active' => false, 'suspended_at' => now()]
                : ['is_active' => false]);
            $engine = $this->mock(WhatsAppOrderingEngine::class);
            $sender = $this->mock(WhatsAppOrderingSender::class);
            $engine->shouldNotReceive('handle');
            $sender->shouldNotReceive('sendText');
            try {
                $this->job($records)->handle($engine, $sender, app(WhatsAppChannelContextResolver::class));
                $this->fail('Stale context was processed.');
            } catch (\Throwable) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, WhatsAppMessage::query()->where('direction', 'outbound')->count());
    }

    private function records(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Queue Tenant', 'slug' => 'queue-'.Str::lower(Str::random(10)), 'is_active' => true,
        ]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true, 'is_accepting_orders' => true]);
        $profile = WhatsAppProviderProfile::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'name' => 'Queue Profile', 'ownership_mode' => 'restaurant_owned',
            'provider' => 'msg91', 'credentials' => ['webhook_secret' => 'queue-test-secret'],
            'status' => 'connected', 'is_active' => true,
        ]);
        $number = WhatsAppPhoneNumber::query()->create([
            'provider_profile_id' => $profile->id, 'provider_phone_id' => 'queue-phone-'.Str::random(8),
            'display_number' => '+910000000001', 'status' => 'connected', 'is_active' => true,
        ]);
        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'provider_profile_id' => $profile->id, 'phone_number_id' => $number->id,
            'ownership_mode' => 'restaurant_owned', 'allowed_branch_ids' => [$branch->id],
            'capabilities' => ['ordering' => true], 'is_active' => true,
        ]);
        $conversation = WhatsAppConversation::query()->withoutGlobalTenant()->create([
            'uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'assignment_id' => $assignment->id, 'customer_phone' => '919999999999', 'state' => 'bot',
        ]);
        $message = WhatsAppMessage::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'conversation_id' => $conversation->id,
            'provider_message_id' => 'inbound-'.Str::uuid(), 'direction' => 'inbound',
            'type' => 'text', 'body' => 'HI', 'payload' => [], 'status' => 'received',
        ]);
        $event = WhatsAppWebhookEvent::query()->withoutGlobalTenant()->create([
            'tenant_id' => $tenant->id, 'provider_profile_id' => $profile->id,
            'provider_event_id' => 'event-'.Str::uuid(), 'event_type' => 'message',
            'payload_hash' => hash('sha256', 'test'), 'payload' => [], 'status' => 'queued',
        ]);

        return compact('tenant', 'branch', 'profile', 'number', 'assignment', 'conversation', 'message', 'event');
    }

    private function job(array $records): ProcessWhatsAppOrderingMessage
    {
        return new ProcessWhatsAppOrderingMessage(
            $records['tenant']->id, $records['conversation']->id, $records['message']->id, $records['event']->id,
        );
    }
}
