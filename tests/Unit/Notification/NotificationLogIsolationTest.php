<?php

namespace Tests\Unit\Notification;

use Modules\Notification\Models\NotificationLog;
use Modules\Notification\Models\Notification;
use Tests\TestCase;

class NotificationLogIsolationTest extends TestCase
{
    public function test_search_terms_remain_grouped_under_tenant_filter(): void
    {
        $query = NotificationLog::query()->where('tenant_id', 20)->search('customer');

        $this->assertStringContainsString('"tenant_id" = ? and (', $query->toSql());
        $this->assertSame([20, '%customer%', '%customer%'], $query->getBindings());
    }

    public function test_in_app_notification_search_cannot_escape_recipient_filter(): void
    {
        $query = Notification::query()->where('target_user_id', 46)->search('order');

        $this->assertStringContainsString('"target_user_id" = ? and (', $query->toSql());
        $this->assertSame([46, '%order%', '%order%'], $query->getBindings());
    }
}
