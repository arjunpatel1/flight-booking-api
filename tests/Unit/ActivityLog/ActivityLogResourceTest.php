<?php

namespace Tests\Unit\ActivityLog;

use Modules\ActivityLog\Models\ActivityLog;
use Modules\ActivityLog\Transformers\Api\V1\ActivityLogResource;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ActivityLogResourceTest extends TestCase
{
    #[Test]
    public function an_automated_event_has_a_visible_system_actor(): void
    {
        $activity = new ActivityLog;
        $activity->forceFill(['log_name' => 'print_agents.updated']);
        $resource = new ActivityLogResource($activity);
        $method = new ReflectionMethod($resource, 'systemActor');

        $actor = $method->invoke($resource);

        $this->assertSame('Print service', $actor['name']);
        $this->assertSame('System', $actor['role']['display_name']);
        $this->assertTrue($actor['is_system']);
    }
}
