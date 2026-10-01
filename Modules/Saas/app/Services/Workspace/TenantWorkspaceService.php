<?php

namespace Modules\Saas\Services\Workspace;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Order\Models\Order;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Printer\Enum\PrintJobStatus;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\Printer;
use Modules\Printer\Models\PrintJob;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Content\SaasContentCatalogService;
use Modules\User\Models\User;

/**
 * Read-only assembly of everything the restaurant owner's workspace shows:
 * the dashboard overview, the Get Started checklist state, and the download /
 * documentation / support catalogue.
 *
 * This service owns no state and writes nothing. Operational numbers come from
 * the existing domain models (already tenant-isolated by the branch/tenant
 * global scopes, so nothing here re-implements isolation), and the catalogue
 * comes from `config('saas.workspace')`.
 */
class TenantWorkspaceService
{
    public function __construct(
        private readonly SaasContentCatalogService $catalog,
    ) {
    }

    /**
     * Dashboard payload. Every section degrades to a null/zero shape rather than
     * failing, so one unavailable subsystem cannot blank the owner's dashboard.
     */
    public function overview(Tenant $tenant): array
    {
        return [
            'restaurant' => $this->restaurant($tenant),
            'subscription' => $this->subscription($tenant),
            'today' => $this->today(),
            'devices' => $this->devices(),
            'printers' => $this->printers(),
            'background' => $this->background($tenant),
            'setup' => $this->setup($tenant),
            'support' => $this->supportStatus(),
            'announcements' => $this->announcements(),
        ];
    }

    /**
     * Download Center, Documentation Center and support contacts.
     */
    public function resources(Tenant $tenant): array
    {
        $features = app(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class)->features($tenant);
        return [
            'entitlements' => $features,
            'artifacts' => collect($this->artifacts())->filter(function ($artifact) use ($features) {
                $required = match ($artifact['key'] ?? '') {
                    'waiter_app' => 'waiter_app', 'windows_agent', 'printer_utility' => 'printer',
                    'kitchen_app' => 'kitchen', 'customer_display' => 'customer_display', default => null,
                };
                return $required === null || in_array($required, $features, true);
            })->values()->all(),
            'documentation' => $this->documentation(),
            'support' => $this->support(),
            'content_revision' => $this->catalog->revision(),
            'updates' => $this->catalog->updates(),
            'activation' => in_array('waiter_app', $features, true) ? [
                'available' => true,
                'mode' => 'single_use',
            ] : null,
        ];
    }

    private function restaurant(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'domain' => $tenant->domain,
            'contact_email' => $tenant->contact_email,
            'contact_phone' => $tenant->contact_phone,
            'is_active' => (bool) $tenant->is_active,
            'status' => $tenant->is_active ? 'active' : 'suspended',
            'branches' => Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'created_at' => optional($tenant->created_at)->toIso8601String(),
        ];
    }

    /**
     * Current subscription and trial countdown. `days_remaining` is null when
     * there is no end date rather than 0, so the UI can distinguish "unlimited"
     * from "expires today".
     */
    private function subscription(Tenant $tenant): array
    {
        $subscription = $tenant->subscriptions()
            ->with('plan:id,name,code,price,currency,billing_cycle')
            ->latest('id')
            ->first();

        if (! $subscription) {
            return [
                'status' => 'none', 'plan' => null, 'is_trial' => false,
                'trial_ends_at' => null, 'ends_at' => null,
                'days_remaining' => null, 'is_expiring_soon' => false,
            ];
        }

        $isTrial = $subscription->status === 'trial';
        $expiry = $isTrial
            ? $subscription->trial_ends_at ?? $subscription->ends_at
            : $subscription->ends_at;

        $daysRemaining = $expiry instanceof Carbon
            ? max(0, (int) now()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false))
            : null;

        return [
            'status' => $subscription->status,
            'plan' => $subscription->plan?->only(['id', 'name', 'code', 'price', 'currency', 'billing_cycle']),
            'is_trial' => $isTrial,
            'trial_ends_at' => optional($subscription->trial_ends_at)->toIso8601String(),
            'ends_at' => optional($subscription->ends_at)->toIso8601String(),
            'days_remaining' => $daysRemaining,
            'is_expiring_soon' => $daysRemaining !== null && $daysRemaining <= 7,
        ];
    }

    /**
     * Today's trading numbers. Scoped automatically to the caller's tenant by
     * the HasBranch global scopes.
     */
    private function today(): array
    {
        return [
            'sales' => (float) Order::withoutCanceledOrders()->whereDate('created_at', today())->sum('total'),
            'orders' => Order::withoutCanceledOrders()->whereDate('created_at', today())->count(),
            // Keep this live snapshot identical to the order drawer and the
            // executive dashboard. A completed but unpaid order remains in
            // the payment workflow, while paid/cancelled/refunded orders do
            // not count as active.
            'active_orders' => Order::paymentPendingActiveOrders()->count(),
        ];
    }

    /**
     * Terminal fleet. Uses the same offline threshold the POS terminal status
     * endpoint uses, so the dashboard and the device list never disagree.
     */
    private function devices(): array
    {
        $offlineAfterSeconds = (int) config('pos.terminal.offline_after_seconds', 90);

        // Counted in SQL rather than by loading the fleet into memory: a large
        // chain has thousands of terminals and this runs on every dashboard.
        return [
            'total' => PosTerminalDevice::query()->count(),
            'online' => PosTerminalDevice::query()
                ->where('is_disabled', false)
                ->where('last_seen_at', '>=', now()->subSeconds($offlineAfterSeconds))
                ->count(),
        ];
    }

    /**
     * Printer readiness is reported from the agents, because a configured
     * printer with no live agent cannot actually print.
     */
    private function printers(): array
    {
        $offlineAfter = now()->subMinutes(
            (int) config('printer.diagnostics.agent_offline_after_minutes', 5)
        );

        $agents = PrintAgent::query()->count();
        $online = PrintAgent::query()
            ->where('is_active', true)
            ->where('last_seen_at', '>', $offlineAfter)
            ->count();

        return [
            'printers' => Printer::query()->count(),
            'agents' => $agents,
            'agents_online' => $online,
            'status' => match (true) {
                $agents === 0 => 'not_configured',
                $online === 0 => 'offline',
                $online < $agents => 'degraded',
                default => 'healthy',
            },
        ];
    }

    private function background(Tenant $tenant): array
    {
        $branchIds = Branch::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('id');
        $today = now()->toDateString();

        $printJobs = collect();
        if (Schema::hasTable('print_jobs')) {
            $printJobs = match (true) {
                Schema::hasColumn('print_jobs', 'tenant_id') => DB::table('print_jobs')->where('tenant_id', $tenant->id)->get(),
                Schema::hasColumn('print_jobs', 'branch_id') => DB::table('print_jobs')->whereIn('branch_id', $branchIds)->get(),
                default => collect(),
            };
        }

        $notificationLogs = collect();
        if (Schema::hasTable('notification_logs')) {
            $notificationLogs = match (true) {
                Schema::hasColumn('notification_logs', 'tenant_id') => DB::table('notification_logs')->where('tenant_id', $tenant->id)->get(),
                Schema::hasColumn('notification_logs', 'branch_id') => DB::table('notification_logs')->whereIn('branch_id', $branchIds)->get(),
                default => collect(),
            };
        }

        return [
            'today_orders' => Order::withoutCanceledOrders()->whereDate('created_at', $today)->count(),
            'today_print_jobs' => $printJobs->filter(fn ($row) => filled($row->created_at ?? null) && Carbon::parse($row->created_at)->toDateString() === $today)->count(),
            'pending_print_jobs' => $printJobs->whereIn('status', ['pending', 'queued', 'processing'])->count(),
            'failed_print_jobs' => $printJobs->whereIn('status', ['failed', 'error'])->count(),
            'pending_notifications' => $notificationLogs->whereIn('status', ['pending', 'queued'])->count(),
            'failed_notifications' => $notificationLogs->whereIn('status', ['failed', 'error'])->count(),
            'pending_emails' => $notificationLogs->where('channel', 'email')->whereIn('status', ['pending', 'queued'])->count(),
            'background_sync_status' => $this->backgroundSyncStatus($printJobs, $notificationLogs),
        ];
    }

    private function backgroundSyncStatus($printJobs, $notificationLogs): string
    {
        if ($printJobs->whereIn('status', ['failed', 'error'])->isNotEmpty() || $notificationLogs->whereIn('status', ['failed', 'error'])->isNotEmpty()) {
            return 'failed';
        }

        if ($printJobs->whereIn('status', ['pending', 'queued', 'processing'])->isNotEmpty() || $notificationLogs->whereIn('status', ['pending', 'queued'])->isNotEmpty()) {
            return 'processing';
        }

        return 'healthy';
    }

    /**
     * Get Started progress. Steps the system can verify for itself are derived
     * from live data; the rest come from the tenant's saved checklist. Derived
     * steps are reported as such so the UI can show them as automatic.
     */
    public function setup(Tenant $tenant): array
    {
        $saved = (array) data_get($tenant->settings, 'tenant_admin_checklist.completed', []);
        $branchIds = Branch::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->pluck('id');
        $latestProvisioning = $tenant->latestProvisioningRun()->first();
        $provisioningComplete = $latestProvisioning
            && $latestProvisioning->status === 'completed'
            && (int) $latestProvisioning->progress >= 100;
        $hasSubscription = $tenant->subscriptions()
            ->whereIn('status', ['trial', 'active'])
            ->exists();
        $hasDevice = $branchIds->isNotEmpty()
            && PosTerminalDevice::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->exists();
        $hasOrder = $branchIds->isNotEmpty()
            && Order::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->exists();
        $hasOnlinePrintAgent = $branchIds->isNotEmpty()
            && PrintAgent::query()->withoutGlobalScopes()
                ->whereIn('branch_id', $branchIds)
                ->where('is_active', true)
                ->where('last_seen_at', '>', now()->subMinutes((int) config('printer.diagnostics.agent_offline_after_minutes', 5)))
                ->exists();
        $hasStaff = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->count() > 1;

        $derived = [
            'account' => true,
            'domain' => filled($tenant->domain),
            'branch' => $branchIds->isNotEmpty(),
            'staff' => $hasStaff,
            'printer' => $branchIds->isNotEmpty()
                && Printer::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->exists(),
            'devices' => $hasDevice,
            'first_product' => $branchIds->isNotEmpty()
                && DB::table('products')
                    ->join('menus', 'menus.id', '=', 'products.menu_id')
                    ->whereIn('menus.branch_id', $branchIds)
                    ->whereNull('products.deleted_at')
                    ->exists(),
            // A receipt has genuinely printed only when an agent reported success.
            'test_print' => $branchIds->isNotEmpty()
                && PrintJob::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->where('status', PrintJobStatus::Success)->exists(),
            'test_order' => $hasOrder,
        ];

        $completed = array_values(array_unique([
            ...$saved,
            ...array_keys(array_filter($derived)),
        ]));

        $steps = self::CHECKLIST_STEPS;

        // This is the same ten-stage launch definition used by SaaS Admin's
        // Tenant Control Center. The longer checklist below remains useful for
        // guiding owners, but it must not produce a different headline setup
        // percentage for the same restaurant.
        $launchChecks = [
            filled($tenant->name) && filled($tenant->domain),
            $hasSubscription,
            $provisioningComplete,
            $hasDevice,
            $branchIds->isNotEmpty(),
            $hasStaff,
            $hasOnlinePrintAgent,
            $hasOrder,
            (bool) $tenant->is_active && $provisioningComplete,
            (bool) $tenant->is_active && $provisioningComplete && $hasSubscription,
        ];

        return [
            'steps' => $steps,
            'completed' => $completed,
            'derived' => array_keys(array_filter($derived)),
            'dismissed' => (bool) data_get($tenant->settings, 'tenant_admin_checklist.dismissed', false),
            'progress' => (int) round(count(array_filter($launchChecks)) / count($launchChecks) * 100),
            'task_progress' => count($steps) > 0
                ? (int) round(count(array_intersect($completed, $steps)) / count($steps) * 100)
                : 0,
        ];
    }

    /**
     * The Get Started flow. Ordered; the first five originals are retained so
     * checklists saved before this flow existed keep counting.
     */
    public const CHECKLIST_STEPS = [
        'account', 'domain', 'ssl',
        'waiter_app', 'print_agent', 'activate',
        'branch', 'staff', 'printer', 'test_print',
        'first_product', 'test_order',
        // Retained for backward compatibility with the original five-step list.
        'menu', 'floor', 'team', 'payments', 'devices',
    ];

    private function supportStatus(): array
    {
        $support = (array) config('saas.workspace.support', []);

        return [
            'email' => $support['email'] ?? null,
            'phone' => $support['phone'] ?? null,
            'whatsapp' => $support['whatsapp'] ?? null,
            'business_hours' => $support['business_hours'] ?? null,
            'is_open' => $this->withinBusinessHours(),
        ];
    }

    /**
     * Coarse open/closed indicator. Deliberately conservative: without a parsed
     * schedule it reports null (unknown) rather than guessing, so the UI shows
     * the hours text instead of a possibly wrong "we're open" badge.
     */
    private function withinBusinessHours(): ?bool
    {
        return null;
    }

    private function announcements(): array
    {
        return array_values((array) config('saas.workspace.announcements', []));
    }

    /*
     * Downloads, documentation and support now come from the SaaS content
     * catalogue, which falls back to config per content type when nothing has
     * been published yet. Tenants therefore always see the latest published
     * content without this service knowing where it is stored.
     */

    private function artifacts(): array
    {
        return $this->catalog->downloads();
    }

    private function documentation(): array
    {
        return $this->catalog->documentation();
    }

    private function support(): array
    {
        return $this->catalog->support();
    }
}
