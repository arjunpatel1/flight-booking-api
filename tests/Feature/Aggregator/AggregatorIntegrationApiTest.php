<?php

namespace Tests\Feature\Aggregator;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Aggregator\Enums\AggregatorProvider;
use Modules\Aggregator\Jobs\SyncAggregatorIntegrationJob;
use Modules\Aggregator\Models\AggregatorIntegration;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class AggregatorIntegrationApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_authorized_admin_can_create_update_disable_and_delete_integration(): void
    {
        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('create'),
            $this->aggregatorPermission('edit'),
            $this->aggregatorPermission('destroy'),
            $this->aggregatorPermission('show'),
        ]);

        $createResponse = $this->postJson('/api/v1/aggregator-integrations', [
            'provider' => AggregatorProvider::Swiggy->value,
            'name' => 'Swiggy Main',
            'base_url' => 'https://partner.swiggy.test',
            'credentials' => [
                'client_id' => 'swiggy-client',
                'secret' => 'swiggy-secret',
            ],
            'webhook_secret' => 'webhook-secret',
            'settings' => [
                'auto_sync' => true,
                'auto_menu_sync' => true,
                'auto_status_sync' => true,
                'webhook_processing' => true,
                'retry_enabled' => true,
            ],
            'is_active' => true,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('body.provider_id', AggregatorProvider::Swiggy->value)
            ->assertJsonPath('body.is_active', true);

        $integrationId = $createResponse->json('body.id');

        $this->putJson("/api/v1/aggregator-integrations/{$integrationId}", [
            'provider' => AggregatorProvider::Zomato->value,
            'name' => 'Zomato Paused',
            'base_url' => 'https://partner.zomato.test',
            'credentials' => [
                'api_key' => 'zomato-key',
            ],
            'webhook_secret' => 'zomato-secret',
            'settings' => [
                'auto_sync' => false,
                'retry_enabled' => true,
            ],
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('body.provider_id', AggregatorProvider::Zomato->value)
            ->assertJsonPath('body.is_active', false);

        $this->assertDatabaseHas('aggregator_integrations', [
            'id' => $integrationId,
            'provider' => AggregatorProvider::Zomato->value,
            'is_active' => false,
        ]);

        $this->deleteJson("/api/v1/aggregator-integrations/{$integrationId}")
            ->assertOk();

        $this->assertSoftDeleted('aggregator_integrations', [
            'id' => $integrationId,
        ]);
    }

    public function test_invalid_provider_is_rejected(): void
    {
        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('create'),
        ]);

        $this->postJson('/api/v1/aggregator-integrations', [
            'provider' => 'unknown-provider',
            'name' => 'Bad Provider',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('provider');
    }

    public function test_permission_blocks_restricted_integration_actions(): void
    {
        $integration = $this->makeIntegration();
        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('index'),
        ]);

        $this->postJson("/api/v1/aggregator-integrations/{$integration->id}/sync", [
            'type' => 'menu',
        ])->assertForbidden();

        $this->deleteJson("/api/v1/aggregator-integrations/{$integration->id}")
            ->assertForbidden();
    }

    public function test_manual_sync_dispatches_queue_job(): void
    {
        Queue::fake();
        $integration = $this->makeIntegration();

        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('sync'),
        ]);

        $this->postJson("/api/v1/aggregator-integrations/{$integration->id}/sync", [
            'type' => 'menu',
        ])->assertOk();

        Queue::assertPushed(SyncAggregatorIntegrationJob::class, function (SyncAggregatorIntegrationJob $job) use ($integration) {
            return $job->integrationId === $integration->id
                && $job->type === 'menu'
                && $job->queue === 'aggregator-menu';
        });
    }

    public function test_form_meta_exposes_provider_capabilities(): void
    {
        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('create'),
        ]);

        $this->getJson('/api/v1/aggregator-integrations/form/meta')
            ->assertOk()
            ->assertJsonPath('body.provider_capabilities.swiggy.supports_menu_sync', false)
            ->assertJsonPath('body.provider_capabilities.zomato.supports_status_push', true)
            ->assertJsonPath('body.provider_documentation.zomato.status', 'public_reference_available')
            ->assertJsonPath('body.provider_documentation.ondc.status', 'public_protocol_available');
    }
}
