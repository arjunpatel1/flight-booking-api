<?php

namespace Tests\Unit\Voice;

use Modules\Order\Models\Order;
use Modules\Branch\Models\Branch;
use Modules\Voice\Jobs\CheckDelayedOrdersJob;
use Modules\Voice\Models\VoiceSetting;
use Modules\Voice\Models\VoiceHistory;
use Modules\Voice\Models\VoiceTemplate;
use Modules\Voice\Services\VoiceAnnouncementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Mockery;

class CheckDelayedOrdersJobTest extends TestCase
{
    use RefreshDatabase;

    private const BRANCH_ONE = 880001;
    private const BRANCH_TWO = 880002;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureBranch(self::BRANCH_ONE);
        $this->ensureBranch(self::BRANCH_TWO);

        VoiceHistory::query()->whereIn('branch_id', [self::BRANCH_ONE, self::BRANCH_TWO])->delete();
        VoiceTemplate::query()->whereIn('branch_id', [self::BRANCH_ONE, self::BRANCH_TWO])->delete();
        VoiceSetting::query()->whereIn('branch_id', [self::BRANCH_ONE, self::BRANCH_TWO])->delete();
        Order::query()->whereIn('branch_id', [self::BRANCH_ONE, self::BRANCH_TWO])->delete();
    }

    public function test_job_processes_delayed_orders(): void
    {
        // Create voice settings with voice enabled
        VoiceSetting::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'voice_enabled' => true,
            'delay_threshold_minutes' => 30,
        ]);

        // Create a delayed order
        $order = Order::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'status' => 'pending',
            'created_at' => now()->subMinutes(35),
        ]);

        VoiceTemplate::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'event_type' => 'OrderDelayed',
            'template_text' => 'Order delayed at table {TableNumber} by {DelayMinutes} minutes.',
            'is_active' => true,
        ]);

        $job = new CheckDelayedOrdersJob();
        $job->handle(app(VoiceAnnouncementService::class));

        // Verify voice history was created for each waiter escalation checkpoint.
        foreach ([15, 20, 30] as $threshold) {
            $this->assertDatabaseHas('voice_history', [
                'order_id' => $order->id,
                'event_type' => "OrderDelayed{$threshold}",
            ]);
        }
    }

    public function test_job_respects_delay_threshold(): void
    {
        // Create voice settings with delayed alerts enabled
        VoiceSetting::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'voice_enabled' => true,
            'delay_threshold_minutes' => 30,
        ]);

        // Create an order that is younger than the first 15 minute checkpoint.
        $order = Order::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'status' => 'pending',
            'created_at' => now()->subMinutes(10),
        ]);

        // Mock the voice service - should not be called
        $voiceService = Mockery::mock(VoiceAnnouncementService::class);
        $voiceService->shouldReceive('triggerTemplateAnnouncement')
            ->never();

        $job = new CheckDelayedOrdersJob();
        $job->handle($voiceService);

        // Verify no voice history was created
        $this->assertDatabaseMissing('voice_history', [
            'order_id' => $order->id,
            'event_type' => 'OrderDelayed15',
        ]);
    }

    public function test_job_prevents_duplicate_alerts(): void
    {
        // Create voice settings
        VoiceSetting::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'voice_enabled' => true,
            'delay_threshold_minutes' => 30,
        ]);

        // Create a delayed order
        $order = Order::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'status' => 'pending',
            'created_at' => now()->subMinutes(35),
        ]);

        // Create existing voice history for this order
        VoiceHistory::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'order_id' => $order->id,
            'event_type' => 'OrderDelayed30',
            'created_at' => now()->subMinutes(10),
        ]);

        // Mock the voice service - only earlier checkpoints should be called.
        $voiceService = Mockery::mock(VoiceAnnouncementService::class);
        $voiceService->shouldReceive('triggerTemplateAnnouncement')
            ->twice()
            ->andReturn(true);

        $job = new CheckDelayedOrdersJob();
        $job->handle($voiceService);

        // Verify the 30 minute checkpoint was not duplicated.
        $this->assertSame(
            1,
            VoiceHistory::query()->where('branch_id', self::BRANCH_ONE)->count()
        );
    }

    public function test_job_handles_voice_disabled_setting(): void
    {
        // Create voice settings with voice disabled
        VoiceSetting::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'voice_enabled' => false,
            'delay_threshold_minutes' => 30,
        ]);

        // Create a delayed order
        $order = Order::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'status' => 'pending',
            'created_at' => now()->subMinutes(35),
        ]);

        // Mock the voice service - should not be called
        $voiceService = Mockery::mock(VoiceAnnouncementService::class);
        $voiceService->shouldReceive('triggerTemplateAnnouncement')
            ->never();

        $job = new CheckDelayedOrdersJob();
        $job->handle($voiceService);

        // Verify no voice history was created
        $this->assertDatabaseMissing('voice_history', [
            'order_id' => $order->id,
            'event_type' => 'OrderDelayed15',
        ]);
    }

    public function test_job_uses_correct_queue(): void
    {
        Queue::fake();

        CheckDelayedOrdersJob::dispatch();

        Queue::assertPushedOn('voice', CheckDelayedOrdersJob::class);
    }

    public function test_job_has_retry_configuration(): void
    {
        $job = new CheckDelayedOrdersJob();

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([30, 60, 120], $job->backoff);
    }

    public function test_job_handles_multiple_branches(): void
    {
        // Create voice settings for multiple branches
        VoiceSetting::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'voice_enabled' => true,
            'delay_threshold_minutes' => 30,
        ]);

        VoiceSetting::factory()->create([
            'branch_id' => self::BRANCH_TWO,
            'voice_enabled' => true,
            'delay_threshold_minutes' => 30,
        ]);

        // Create delayed orders for both branches
        Order::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'status' => 'pending',
            'created_at' => now()->subMinutes(35),
        ]);

        Order::factory()->create([
            'branch_id' => self::BRANCH_TWO,
            'status' => 'pending',
            'created_at' => now()->subMinutes(35),
        ]);

        VoiceTemplate::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'event_type' => 'OrderDelayed',
            'template_text' => 'Order delayed at table {TableNumber} by {DelayMinutes} minutes.',
            'is_active' => true,
        ]);
        VoiceTemplate::factory()->create([
            'branch_id' => self::BRANCH_TWO,
            'event_type' => 'OrderDelayed',
            'template_text' => 'Order delayed at table {TableNumber} by {DelayMinutes} minutes.',
            'is_active' => true,
        ]);

        $job = new CheckDelayedOrdersJob();
        $job->handle(app(VoiceAnnouncementService::class));

        // Verify voice history was created for both orders at 15/20/30 minutes.
        $this->assertSame(
            6,
            VoiceHistory::query()
                ->whereIn('branch_id', [self::BRANCH_ONE, self::BRANCH_TWO])
                ->count()
        );
    }

    public function test_job_ignores_completed_orders(): void
    {
        // Create voice settings
        VoiceSetting::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'voice_enabled' => true,
            'delay_threshold_minutes' => 30,
        ]);

        // Create a completed order (should not trigger)
        $order = Order::factory()->create([
            'branch_id' => self::BRANCH_ONE,
            'status' => 'completed',
            'created_at' => now()->subMinutes(35),
        ]);

        // Mock the voice service - should not be called
        $voiceService = Mockery::mock(VoiceAnnouncementService::class);
        $voiceService->shouldReceive('triggerTemplateAnnouncement')
            ->never();

        $job = new CheckDelayedOrdersJob();
        $job->handle($voiceService);

        // Verify no voice history was created
        $this->assertDatabaseMissing('voice_history', [
            'order_id' => $order->id,
            'event_type' => 'OrderDelayed15',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function ensureBranch(int $id): void
    {
        if (!Branch::query()->withoutGlobalScopes()->withTrashed()->whereKey($id)->exists()) {
            Branch::factory()->create(['id' => $id]);
        }
    }
}
