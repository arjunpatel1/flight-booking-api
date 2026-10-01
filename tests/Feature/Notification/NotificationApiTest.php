<?php

namespace Tests\Feature\Notification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Notification\Models\Notification;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_user_can_list_count_and_mark_notifications_read(): void
    {
        $user = $this->actingAsUserWithPermissions([
            'admin.notifications.index',
            'admin.notifications.read',
        ]);

        $notification = Notification::create([
            'target_user_id' => $user->id,
            'title' => 'Webhook failed',
            'message' => 'Aggregator webhook failed',
            'type' => 'aggregator.webhook_failed',
            'severity' => NotificationSeverity::Error,
            'action_url' => '/admin/aggregator-webhook-events',
        ]);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('body.data.0.title', 'Webhook failed')
            ->assertJsonPath('body.data.0.severity', NotificationSeverity::Error->value)
            ->assertJsonPath('body.data.0.is_read', false);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('body.unread_count', 1);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('body.success', true);

        $this->assertDatabaseMissing('notifications', [
            'id' => $notification->id,
            'read_at' => null,
        ]);
    }

    public function test_mark_all_read_and_clear_are_permission_protected(): void
    {
        $user = $this->actingAsUserWithPermissions([
            'admin.notifications.index',
            'admin.notifications.read',
            'admin.notifications.clear',
        ]);

        Notification::create([
            'target_user_id' => $user->id,
            'title' => 'Queue warning',
            'type' => 'system.queue',
            'severity' => NotificationSeverity::Warning,
        ]);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'target_user_id' => $user->id,
            'read_at' => null,
        ]);

        $this->deleteJson('/api/v1/notifications/clear')
            ->assertOk();

        $this->assertDatabaseCount('notifications', 0);
    }
}
