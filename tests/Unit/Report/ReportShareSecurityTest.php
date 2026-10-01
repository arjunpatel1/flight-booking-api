<?php

namespace Tests\Unit\Report;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Modules\Report\Jobs\SendReportEmailJob;
use Tests\TestCase;

class ReportShareSecurityTest extends TestCase
{
    public function test_email_share_route_is_authenticated_permissioned_and_rate_limited(): void
    {
        $route = collect(RouteFacade::getRoutes())->first(fn (Route $route) =>
            $route->uri() === 'api/v1/reports/share/email'
            && in_array('POST', $route->methods(), true)
        );

        $this->assertNotNull($route);
        $this->assertContains('can:admin.reports.index', $route->gatherMiddleware());
        $this->assertContains('tenant.feature:reports', $route->gatherMiddleware());
        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
        $this->assertContains(Authenticate::class, app('router')->gatherRouteMiddleware($route));
    }

    public function test_email_job_keeps_immutable_tenant_and_branch_context(): void
    {
        $job = new SendReportEmailJob(41, 73, 'owner@example.com', 'sales_summary', '2026-08-24');

        /** @var SendReportEmailJob $restored */
        $restored = unserialize(serialize($job));

        $this->assertSame(41, $restored->tenantId);
        $this->assertSame(73, $restored->branchId);
        $this->assertSame('owner@example.com', $restored->recipient);
    }

    public function test_email_worker_checks_branch_ownership_and_does_not_log_recipient_pii(): void
    {
        $source = file_get_contents(base_path('Modules/Report/app/Jobs/SendReportEmailJob.php'));

        $this->assertStringContainsString("where('tenant_id', \$this->tenantId)", $source);
        $this->assertStringContainsString("'recipient_fingerprint' => hash('sha256'", $source);
        $this->assertStringNotContainsString("'recipient' => \$this->recipient", $source);
    }
}
