<?php

namespace Tests\Unit\Core;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ObservabilityAccessTest extends TestCase
{
    public function test_pulse_dashboard_gate_allows_super_admin_only(): void
    {
        $superAdmin = new class extends Authenticatable {
            public function isSuperAdmin(): bool
            {
                return true;
            }
        };

        $regularUser = new class extends Authenticatable {
            public function isSuperAdmin(): bool
            {
                return false;
            }
        };

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewPulse'));
        $this->assertFalse(Gate::forUser($regularUser)->allows('viewPulse'));
    }
}
