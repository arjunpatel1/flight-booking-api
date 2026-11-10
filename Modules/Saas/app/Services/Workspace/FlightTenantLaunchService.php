<?php

namespace Modules\Saas\Services\Workspace;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Saas\Enums\CustomerLifecycleStage;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Lifecycle\CustomerLifecycleService;
use Modules\User\Models\User;

/**
 * Launch readiness of a flight agency: the same nine steps as the Onboarding
 * journey in the SaaS tenant workspace (TenantReadinessBoard.vue).
 *
 * TenantWorkspaceService::setup() scores restaurant setup (POS devices,
 * printers, menu products, test orders) and can never reach 100% for a flight
 * agency, so the SaaS registry and workspace use this instead.
 *
 * An agency created by hand has no provisioning run. When it is plainly live
 * (active, on a plan, has an owner, has flight bookings) its setup counts as
 * done; re-running provisioning on it would reset the owner's login.
 */
class FlightTenantLaunchService
{
    /**
     * @return array{progress: int, provisioned: bool, live_without_provisioning: bool, completed_steps: int, total_steps: int}
     */
    public function evaluate(Tenant $tenant): array
    {
        $run = $tenant->relationLoaded('latestProvisioningRun')
            ? $tenant->latestProvisioningRun
            : $tenant->latestProvisioningRun()->first();
        $runProvisioned = $run && $run->status === 'completed' && (int) $run->progress >= 100;

        $hasPlan = $tenant->subscriptions()->whereIn('status', ['trial', 'active'])->exists();
        $users = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $bookings = $this->bookingCounts($tenant);
        $active = (bool) $tenant->is_active;

        $liveWithoutProvisioning = $run === null && $active && $hasPlan && $users >= 1 && $bookings['total'] > 0;
        $provisioned = $runProvisioned || $liveWithoutProvisioning;

        $checks = [
            filled($tenant->name) && filled($tenant->domain),                  // Tenant created
            $hasPlan,                                                          // Plan & trial
            $provisioned,                                                      // Provisioning
            $users > 1,                                                        // Agents added
            $bookings['total'] > 0,                                            // First booking
            $bookings['paid'] > 0,                                             // First payment
            $bookings['ticketed'] > 0,                                         // First ticket issued
            $active && $provisioned,                                           // Go live
            $this->isHealthy($tenant),                                         // Healthy
        ];
        $done = count(array_filter($checks));

        return [
            'progress' => (int) round($done / count($checks) * 100),
            'provisioned' => $provisioned,
            'live_without_provisioning' => $liveWithoutProvisioning,
            'completed_steps' => $done,
            'total_steps' => count($checks),
        ];
    }

    /**
     * Query constraint for "setup done" (provisioned or live without a run), so
     * registry counters and filters agree with evaluate().
     */
    public static function constrainProvisioned(Builder $tenants): void
    {
        $tenants->where(function (Builder $done) {
            $done->whereHas('latestProvisioningRun', fn (Builder $run) => $run
                ->where('status', 'completed')
                ->where('progress', '>=', 100));

            $done->orWhere(fn (Builder $live) => self::constrainLiveWithoutProvisioning($live));
        });
    }

    public static function constrainLiveWithoutProvisioning(Builder $tenants): void
    {
        $tenants->whereDoesntHave('provisioningRuns')
            ->where('is_active', true)
            ->whereHas('subscriptions', fn (Builder $subscription) => $subscription->whereIn('status', ['trial', 'active']))
            ->whereExists(fn ($user) => $user->selectRaw('1')->from('users')->whereColumn('users.tenant_id', 'tenants.id'));

        if (Schema::hasTable('flight_bookings')) {
            $tenants->whereExists(fn ($booking) => $booking->selectRaw('1')
                ->from('flight_bookings')
                ->whereColumn('flight_bookings.tenant_id', 'tenants.id'));
        } else {
            $tenants->whereRaw('1 = 0');
        }
    }

    /** @return array{total: int, paid: int, ticketed: int} */
    private function bookingCounts(Tenant $tenant): array
    {
        if (! Schema::hasTable('flight_bookings')) {
            return ['total' => 0, 'paid' => 0, 'ticketed' => 0];
        }

        $row = DB::table('flight_bookings')
            ->where('tenant_id', $tenant->id)
            ->when(Schema::hasColumn('flight_bookings', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN payment_status = 'PAID' THEN 1 ELSE 0 END) AS paid")
            ->selectRaw("SUM(CASE WHEN ticket_status = 'ISSUED' THEN 1 ELSE 0 END) AS ticketed")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'paid' => (int) ($row->paid ?? 0),
            'ticketed' => (int) ($row->ticketed ?? 0),
        ];
    }

    private function isHealthy(Tenant $tenant): bool
    {
        try {
            return app(CustomerLifecycleService::class)->stageForTenant($tenant) === CustomerLifecycleStage::Healthy;
        } catch (\Throwable) {
            return false;
        }
    }
}
