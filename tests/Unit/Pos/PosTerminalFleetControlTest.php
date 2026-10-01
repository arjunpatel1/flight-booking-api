<?php

namespace Tests\Unit\Pos;

use Modules\Pos\Models\PosTerminalDevice;
use Tests\TestCase;

/**
 * Unit coverage for the POS terminal fleet control plane logic — the security
 * directives delivered on heartbeat. Pure in-memory model logic, no database.
 */
class PosTerminalFleetControlTest extends TestCase
{
    public function test_enabled_terminal_on_current_version_gets_no_directives(): void
    {
        $device = new PosTerminalDevice();
        $device->is_disabled = false;
        $device->app_version = '2.0.0';

        $directives = $device->controlDirectives('2.0.0');

        $this->assertFalse($directives['force_logout']);
        $this->assertFalse($directives['must_upgrade']);
        $this->assertNull($directives['disabled_reason']);
    }

    public function test_disabled_terminal_is_force_logged_out_with_reason(): void
    {
        $device = new PosTerminalDevice();
        $device->is_disabled = true;
        $device->disabled_reason = 'Reported stolen';
        $device->app_version = '2.0.0';

        $directives = $device->controlDirectives('2.0.0');

        $this->assertTrue($directives['force_logout']);
        $this->assertSame('Reported stolen', $directives['disabled_reason']);
    }

    public function test_below_minimum_version_must_upgrade(): void
    {
        $device = new PosTerminalDevice();
        $device->is_disabled = false;
        $device->app_version = '1.4.9';

        $this->assertTrue($device->controlDirectives('1.5.0')['must_upgrade']);
        $this->assertFalse($device->controlDirectives('1.4.9')['must_upgrade']);
        $this->assertFalse($device->controlDirectives('1.0.0')['must_upgrade']);
    }

    public function test_no_min_version_configured_never_forces_upgrade(): void
    {
        $device = new PosTerminalDevice();
        $device->app_version = '0.0.1';

        $this->assertFalse($device->controlDirectives(null)['must_upgrade']);
    }

    public function test_version_comparison_handles_suffixes_and_widths(): void
    {
        $this->assertSame(-1, PosTerminalDevice::compareVersions('1.2.3', '1.2.4'));
        $this->assertSame(1, PosTerminalDevice::compareVersions('1.10.0', '1.9.9'));
        $this->assertSame(0, PosTerminalDevice::compareVersions('1.2.3', '1.2.3'));
        $this->assertSame(0, PosTerminalDevice::compareVersions('1.2.3+45', '1.2.3-rc2'));
        $this->assertSame(1, PosTerminalDevice::compareVersions('2', '1.9.9'));
        $this->assertSame(0, PosTerminalDevice::compareVersions('bad-version', '1.9.9'));
    }
}
