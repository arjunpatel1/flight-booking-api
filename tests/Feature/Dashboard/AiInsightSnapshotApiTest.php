<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Dashboard\Jobs\GenerateAiInsightSnapshot;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class AiInsightSnapshotApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_snapshot_generation_is_queued_and_history_is_available(): void
    {
        Queue::fake();
        $this->actingAsUserWithPermissions(['admin.dashboards.analytics']);

        $this->postJson('/api/v1/dashboards/smart-insight-snapshots')
            ->assertOk()
            ->assertJsonPath('body.queued', true);

        Queue::assertPushed(GenerateAiInsightSnapshot::class);

        $this->getJson('/api/v1/dashboards/smart-insight-snapshots')
            ->assertOk()
            ->assertJsonStructure(['body' => ['data']]);
    }
}
