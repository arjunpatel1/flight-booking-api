<?php

namespace Tests\Feature\Tracking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class LiveTrackingApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_live_tracking_index_and_show_return_order_source_and_timeline(): void
    {
        $this->actingAsUserWithPermissions([
            'admin.live_tracking.index',
            'admin.live_tracking.show',
        ]);

        $branch = $this->makeBranch();
        $order = $this->makeOrder($branch);

        $this->getJson('/api/v1/live-tracking')
            ->assertOk()
            ->assertJsonPath('body.data.0.id', $order->id)
            ->assertJsonPath('body.data.0.source_label', 'Takeaway');

        $this->getJson("/api/v1/live-tracking/{$order->reference_no}")
            ->assertOk()
            ->assertJsonPath('body.id', $order->id)
            ->assertJsonPath('body.source.source_type', 'takeaway')
            ->assertJsonStructure(['body' => ['timeline']]);
    }
}
