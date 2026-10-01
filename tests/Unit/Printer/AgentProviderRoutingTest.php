<?php

namespace Tests\Unit\Printer;

use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Services\AgentPoll\AgentPollService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AgentProviderRoutingTest extends TestCase
{
    #[DataProvider('platforms')]
    public function test_agent_platform_maps_to_only_its_supported_printer_provider(
        string $platform,
        string $provider,
    ): void {
        $agent = new PrintAgent(['platform' => $platform]);
        $method = new ReflectionMethod(AgentPollService::class, 'providerTypeFor');

        $this->assertSame($provider, $method->invoke(new AgentPollService, $agent));
    }

    public static function platforms(): array
    {
        return [
            'Android local agent' => ['android', 'android_app'],
            'Windows agent' => ['windows', 'windows_agent'],
            'Ubuntu agent' => ['ubuntu', 'ubuntu_agent'],
            'Linux agent' => ['linux', 'ubuntu_agent'],
        ];
    }
}
