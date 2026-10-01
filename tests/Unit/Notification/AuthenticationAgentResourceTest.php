<?php
namespace Tests\Unit\Notification;
use Illuminate\Http\Request;
use Modules\ActivityLog\Models\AuthenticationLog;
use Modules\ActivityLog\Transformers\Api\V1\AuthenticationLogResource;
use Tests\TestCase;
class AuthenticationAgentResourceTest extends TestCase
{
    public function test_missing_device_metadata_remains_unknown(): void
    {
        $log = new AuthenticationLog;
        $log->setRawAttributes(['id' => 1, 'user_agent' => null, 'ip_address' => '127.0.0.1', 'login_at' => null, 'logout_at' => null]);
        $data = (new AuthenticationLogResource($log))->toArray(Request::create('/'));
        $this->assertNull($data['agent']['desktop']);
    }
    public function test_mobile_agent_is_boolean_false_not_a_truthy_translated_string(): void
    {
        $log = new AuthenticationLog;
        $log->setRawAttributes(['id' => 1, 'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', 'ip_address' => '127.0.0.1', 'login_at' => null, 'logout_at' => null]);
        $data = (new AuthenticationLogResource($log))->toArray(Request::create('/'));
        $this->assertFalse($data['agent']['desktop']);
        $this->assertNotEmpty($data['agent']['browser']);
    }
}
