<?php

namespace Tests\Unit\Saas;

use Modules\Saas\Logging\TenantLogProcessor;
use Modules\Saas\Support\TenantContext;
use Modules\Saas\Support\TenantResourceNaming;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * Phase 2 — runtime tenant-awareness contract.
 *
 * These verify the naming, context and log-enrichment guarantees without any
 * database (they exercise pure logic), so they run in CI regardless of the
 * pdo_sqlite gap. The queue propagation is proven separately as an integration
 * test where a worker is available.
 */
class RuntimeIsolationTest extends TestCase
{
    private function naming(): TenantResourceNaming
    {
        return app(TenantResourceNaming::class);
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->context()->clear();
        parent::tearDown();
    }

    public function test_cache_keys_are_namespaced_per_tenant(): void
    {
        $naming = $this->naming();

        $this->context()->clear();
        $this->assertSame('shared:product_cache', $naming->cacheKey('product_cache'),
            'With no tenant, keys partition under "shared", never at the root.');

        $this->context()->setId(7);
        $this->assertSame('tenant:7:product_cache', $naming->cacheKey('product_cache'));

        $this->context()->setId(9);
        $this->assertSame('tenant:9:product_cache', $naming->cacheKey('product_cache'),
            'A different tenant must produce a different key.');
    }

    public function test_cache_key_namespacing_is_idempotent(): void
    {
        $this->context()->setId(7);
        $once = $this->naming()->cacheKey('menu');

        $this->assertSame($once, $this->naming()->cacheKey($once),
            'Wrapping an already-namespaced key must not double-prefix.');
    }

    public function test_channel_names_carry_the_tenant_when_bound(): void
    {
        $naming = $this->naming();

        $this->context()->clear();
        $this->assertSame('pos.orders.branch.6', $naming->channel('pos.orders.branch.6'),
            'No tenant bound → channel unchanged (preserves the current client contract).');

        $this->context()->setId(7);
        $this->assertSame('tenant.7.pos.orders.branch.6', $naming->channel('pos.orders.branch.6'),
            'The canonical tenant-aware form new channels converge on.');
    }

    public function test_redis_and_storage_prefixes_are_tenant_scoped(): void
    {
        $this->context()->setId(7);

        $this->assertSame('tenant:7:', $this->naming()->redisPrefix());
        $this->assertSame('tenants/7', $this->naming()->storagePrefix());
    }

    public function test_context_carries_across_a_simulated_queue_boundary(): void
    {
        $context = $this->context();
        $key = TenantResourceNaming::QUEUE_PAYLOAD_KEY;

        // dispatch: stamp tenant into the payload
        $context->setId(7);
        $payload = [$key => $context->id()];

        // worker picks up a *different* residual context from a prior job
        $context->setId(999);

        // JobProcessing restores from payload
        $context->setId((int) $payload[$key]);
        $this->assertSame(7, $context->id(), 'Job runs under the tenant it was dispatched for.');

        // JobProcessed clears — the next job must not inherit this one's tenant
        $context->clear();
        $this->assertNull($context->id());
    }

    public function test_log_processor_appends_context_without_mutating_the_record(): void
    {
        $this->context()->setId(7);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'order placed',
            context: ['order_id' => 123],
            extra: ['existing' => 'kept'],
        );

        $out = (new TenantLogProcessor())($record);

        $this->assertSame(7, $out->extra['tenant_id']);
        $this->assertSame('kept', $out->extra['existing'], 'Existing extra is preserved.');
        $this->assertSame('order placed', $out->message, 'The message body is untouched.');
        $this->assertSame(['order_id' => 123], $out->context, 'Context is untouched.');
    }

    public function test_log_processor_never_injects_credentials(): void
    {
        $this->context()->setId(7);
        $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Info, 'x', [], []);

        $extra = (new TenantLogProcessor())($record)->extra;

        foreach (['password', 'db_password', 'secret', 'token', 'api_key'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $extra);
        }
        $this->assertSame(['tenant_id'], array_keys(array_intersect_key($extra, ['tenant_id' => 1])));
    }

    public function test_log_tap_is_registered_on_file_channels(): void
    {
        foreach (['single', 'daily'] as $channel) {
            $this->assertContains(
                \Modules\Saas\Logging\TenantLogTap::class,
                config("logging.channels.{$channel}.tap", []),
                "The {$channel} channel must tap the tenant log processor."
            );
        }
    }
}
