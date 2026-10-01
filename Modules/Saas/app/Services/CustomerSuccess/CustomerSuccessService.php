<?php

namespace Modules\Saas\Services\CustomerSuccess;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\SaasBillingInvoice;
use Modules\Saas\Models\SaasCustomerSuccessRecord;
use Modules\Saas\Models\SaasProvisioningRun;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantSubscription;
use Modules\Saas\Enums\CustomerLifecycleStage;
use Modules\Saas\Services\Lifecycle\CustomerLifecycleService;

class CustomerSuccessService
{
    public function __construct(private readonly CustomerLifecycleService $lifecycle) {}

    public function dashboard(): array
    {
        $tenants = Tenant::query()
            ->withoutGlobalActive()
            ->with($this->tenantRelations())
            ->get();

        $rows = $tenants->map(fn (Tenant $tenant) => $this->tenantRow($tenant));
        $records = $this->records();

        return [
            'summary' => $this->summary($rows),
            'pipeline' => $this->pipeline($rows),
            'customers' => $rows->values(),
            'tasks' => $this->tasks($rows)->values(),
            'records' => $records,
            'renewals' => $this->renewals($rows)->values(),
            'communications' => $this->communications(),
            'analytics' => [...$this->analytics($rows), 'persisted_open_tasks' => $records->where('type', 'task')->whereIn('status', ['open', 'in_progress'])->count()],
            'templates' => $this->templates(),
        ];
    }

    private function records(): Collection
    {
        if (! Schema::hasTable('saas_customer_success_records')) return collect();

        return SaasCustomerSuccessRecord::query()
            ->with(['tenant:id,name', 'assignee:id,name', 'creator:id,name'])
            ->latest()->limit(100)->get()
            ->map(fn (SaasCustomerSuccessRecord $record) => [
                ...$record->only(['id', 'tenant_id', 'type', 'visibility', 'title', 'body', 'priority', 'status']),
                'tenant' => $record->tenant?->name,
                'assigned_to' => $record->assignee?->id,
                'assignee' => $record->assignee?->name,
                'creator' => $record->creator?->name,
                'due_at' => $record->due_at?->toIso8601String(),
                'completed_at' => $record->completed_at?->toIso8601String(),
                'created_at' => $record->created_at?->toIso8601String(),
            ])->values();
    }

    private function tenantRelations(): array
    {
        return array_values(array_filter([
            'branches:id,tenant_id,name,is_active',
            'users:id,tenant_id,name,email,phone,is_active,updated_at',
            Schema::hasTable('tenant_subscriptions') ? 'activeSubscription.plan' : null,
            Schema::hasTable('saas_provisioning_runs') ? 'latestProvisioningRun' : null,
        ]));
    }

    private function tenantRow(Tenant $tenant): array
    {
        $branchIds = $tenant->relationLoaded('branches')
            ? $tenant->branches->pluck('id')->filter()->values()
            : collect();
        $subscription = $tenant->relationLoaded('activeSubscription') ? $tenant->activeSubscription : null;
        $provisioning = $tenant->relationLoaded('latestProvisioningRun') ? $tenant->latestProvisioningRun : null;
        $metrics = $this->usageMetrics($branchIds);
        $billing = $this->billing($tenant->id);
        $onboarding = $this->onboarding($tenant, $provisioning, $metrics);
        $health = $this->healthScore($tenant, $subscription, $provisioning, $metrics, $billing);
        $lifecycle = $this->lifecycle->stageForTenant($tenant);

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'restaurant' => $tenant->name,
            'owner' => $tenant->contact_name,
            'email' => $tenant->contact_email,
            'phone' => $tenant->contact_phone,
            'whatsapp' => $tenant->contact_phone,
            'domain' => $tenant->domain,
            'status' => $lifecycle->value,
            'lifecycle' => ['stage' => $lifecycle->value, 'label' => $lifecycle->label()],
            'priority' => $health['level'] === 'critical' ? 'high' : ($health['level'] === 'warning' ? 'medium' : 'normal'),
            'tags' => array_values(array_filter([$subscription?->status, $tenant->is_active ? 'active' : 'inactive'])),
            'plan' => $subscription?->plan?->name,
            'subscription' => [
                'status' => $subscription?->status,
                'trial_ends_at' => $subscription?->trial_ends_at?->toIso8601String(),
                'ends_at' => $subscription?->ends_at?->toIso8601String(),
            ],
            'created_at' => $tenant->created_at?->toIso8601String(),
            'last_activity' => $this->lastActivity($tenant, $metrics),
            'health' => $health,
            'onboarding' => $onboarding,
            'billing' => $billing,
            'usage' => [
                ...$metrics,
                'branches' => $tenant->relationLoaded('branches') ? $tenant->branches->count() : 0,
                'users' => $tenant->relationLoaded('users') ? $tenant->users->count() : 0,
            ],
        ];
    }

    private function usageMetrics(Collection $branchIds): array
    {
        if ($branchIds->isEmpty()) {
            return ['orders' => 0, 'payments' => 0, 'revenue' => 0.0, 'open_orders' => 0, 'printer_failures' => 0];
        }

        return [
            'orders' => $this->countByBranches('orders', $branchIds),
            'payments' => $this->countByBranches('payments', $branchIds),
            'revenue' => $this->sumByBranches('orders', $branchIds, 'total'),
            'open_orders' => $this->countByBranches('orders', $branchIds, ['payment_status', '!=', 'paid']),
            'printer_failures' => $this->countByBranches('printer_jobs', $branchIds, ['status', 'in', ['failed', 'error']]),
        ];
    }

    private function countByBranches(string $table, Collection $branchIds, ?array $where = null): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) {
            return 0;
        }

        $query = \DB::table($table)->whereIn('branch_id', $branchIds);

        if ($where) {
            $where[1] === 'in'
                ? $query->whereIn($where[0], $where[2])
                : $query->where($where[0], $where[1], $where[2]);
        }

        return (int) $query->count();
    }

    private function sumByBranches(string $table, Collection $branchIds, string $column): float
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id') || ! Schema::hasColumn($table, $column)) {
            return 0.0;
        }

        return (float) \DB::table($table)->whereIn('branch_id', $branchIds)->sum($column);
    }

    private function billing(int $tenantId): array
    {
        if (! Schema::hasTable('saas_billing_invoices')) {
            return ['open_invoices' => 0, 'past_due' => 0, 'outstanding' => 0.0, 'last_invoice_status' => null];
        }

        $invoices = SaasBillingInvoice::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->get();

        return [
            'open_invoices' => $invoices->whereIn('status', ['draft', 'issued', 'pending', 'overdue'])->count(),
            'past_due' => $invoices->where('status', 'overdue')->count(),
            'outstanding' => (float) $invoices->whereIn('status', ['issued', 'pending', 'overdue'])->sum('amount'),
            'last_invoice_status' => $invoices->sortByDesc('created_at')->first()?->status,
        ];
    }

    private function onboarding(Tenant $tenant, ?SaasProvisioningRun $run, array $metrics): array
    {
        // TenantResource and every SaaS dashboard use the provisioning run as
        // the canonical onboarding percentage. Operational adoption signals
        // remain visible as checks, but never rewrite that percentage.
        $progress = (int) ($run?->progress ?? 0);
        $checks = [
            'first_login' => $tenant->relationLoaded('users') && $tenant->users->contains(fn ($user) => filled($user->updated_at)),
            'first_order' => $metrics['orders'] > 0,
            'first_bill' => $metrics['payments'] > 0,
            'printer_healthy' => $metrics['printer_failures'] === 0,
        ];

        return [
            'status' => $progress >= 100 && $run?->status === 'completed'
                ? 'completed'
                : ($run?->status === 'completed' ? 'in_progress' : ($run?->status ?? 'pending')),
            'current_step' => $run?->current_step,
            'progress' => $progress,
            'checks' => $checks,
            'run_uuid' => $run?->uuid,
            'error' => $run?->error,
        ];
    }

    private function healthScore(Tenant $tenant, ?TenantSubscription $subscription, ?SaasProvisioningRun $run, array $metrics, array $billing): array
    {
        $score = 100;
        $reasons = [];

        if (! $tenant->is_active) {
            $score -= 35;
            $reasons[] = 'Tenant is inactive or suspended.';
        }
        if (in_array($subscription?->status, ['past_due', 'cancelled', 'expired'], true)) {
            $score -= 25;
            $reasons[] = 'Subscription requires attention.';
        }
        if (in_array($run?->status, ['failed', 'partially_completed'], true)) {
            $score -= 20;
            $reasons[] = 'Provisioning is not fully complete.';
        }
        if ($billing['past_due'] > 0 || $billing['outstanding'] > 0) {
            $score -= 15;
            $reasons[] = 'Outstanding billing exists.';
        }
        if ($metrics['printer_failures'] > 0) {
            $score -= 10;
            $reasons[] = 'Printer failures detected.';
        }

        $score = max(0, $score);

        return [
            'score' => $score,
            'level' => $score >= 85 ? 'excellent' : ($score >= 70 ? 'good' : ($score >= 45 ? 'warning' : 'critical')),
            'reasons' => $reasons,
        ];
    }

    private function lastActivity(Tenant $tenant, array $metrics): ?string
    {
        return $tenant->updated_at?->toIso8601String() ?? ($metrics['orders'] > 0 ? now()->toIso8601String() : null);
    }

    private function summary(Collection $rows): array
    {
        return [
            'customers' => $rows->count(),
            'active' => $rows->whereIn('status', [CustomerLifecycleStage::GoLive->value, CustomerLifecycleStage::Healthy->value])->count(),
            'onboarding' => $rows->whereIn('status', [CustomerLifecycleStage::Provisioning->value, CustomerLifecycleStage::Onboarding->value, CustomerLifecycleStage::Training->value])->count(),
            'renewal_due' => $rows->where('status', CustomerLifecycleStage::Renewal->value)->count(),
            'suspended' => $rows->where('status', CustomerLifecycleStage::Cancelled->value)->count(),
            'critical_health' => $rows->where('health.level', 'critical')->count(),
            'mrr' => (float) $rows->sum('billing.outstanding'),
        ];
    }

    private function pipeline(Collection $rows): array
    {
        return collect(CustomerLifecycleStage::cases())->map(fn (CustomerLifecycleStage $stage) => [
            'status' => $stage->value,
            'label' => $stage->label(),
            'count' => $rows->where('status', $stage->value)->count(),
            'tenants' => $rows->where('status', $stage->value)->take(8)->values(),
        ])->values()->all();
    }

    private function tasks(Collection $rows): Collection
    {
        return $rows->flatMap(function (array $row) {
            return collect([
                $row['onboarding']['progress'] < 100 ? $this->task($row, 'Complete onboarding', 'onboarding', 'high') : null,
                $row['billing']['outstanding'] > 0 ? $this->task($row, 'Follow up pending payment', 'renewal', 'high') : null,
                $row['health']['level'] === 'critical' ? $this->task($row, 'Resolve critical health issue', 'support', 'critical') : null,
                $row['usage']['orders'] === 0 ? $this->task($row, 'Schedule first-order training', 'training', 'medium') : null,
            ])->filter();
        })->sortByDesc(fn (array $task) => ['critical' => 3, 'high' => 2, 'medium' => 1][$task['priority']] ?? 0);
    }

    private function task(array $row, string $title, string $type, string $priority): array
    {
        return [
            'id' => "{$row['id']}-{$type}",
            'tenant_id' => $row['id'],
            'tenant' => $row['name'],
            'title' => $title,
            'type' => $type,
            'priority' => $priority,
            'status' => 'open',
        ];
    }

    private function renewals(Collection $rows): Collection
    {
        return $rows
            ->filter(fn (array $row) => in_array($row['status'], [CustomerLifecycleStage::Renewal->value, CustomerLifecycleStage::Cancelled->value], true) || $row['billing']['outstanding'] > 0)
            ->map(fn (array $row) => [
                'tenant_id' => $row['id'],
                'tenant' => $row['name'],
                'plan' => $row['plan'],
                'status' => $row['subscription']['status'],
                'renewal_at' => $row['subscription']['ends_at'] ?? $row['subscription']['trial_ends_at'],
                'outstanding' => $row['billing']['outstanding'],
                'priority' => $row['priority'],
            ]);
    }

    private function communications(): array
    {
        if (! Schema::hasTable('notification_logs')) {
            return ['recent' => [], 'failed' => 0, 'sent' => 0];
        }

        $logs = \DB::table('notification_logs')
            ->where('type', 'tenant.customer_success')
            ->orWhere('type', 'saas.tenant_message')
            ->latest('id')
            ->limit(20)
            ->get();

        return [
            'recent' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'channel' => $log->channel,
                'recipient' => $log->recipient,
                'status' => $log->status,
                'created_at' => $log->created_at,
            ])->values(),
            'failed' => $logs->where('status', 'failed')->count(),
            'sent' => $logs->where('status', 'sent')->count(),
        ];
    }

    private function analytics(Collection $rows): array
    {
        return [
            'trial_conversion' => $rows->count() > 0 ? round(($rows->whereIn('status', [CustomerLifecycleStage::GoLive->value, CustomerLifecycleStage::Healthy->value])->count() / $rows->count()) * 100, 1) : 0,
            'average_health' => round((float) $rows->avg('health.score'), 1),
            'average_onboarding_progress' => round((float) $rows->avg('onboarding.progress'), 1),
            'total_revenue' => (float) $rows->sum('usage.revenue'),
            'open_tasks' => $this->tasks($rows)->count(),
        ];
    }

    private function templates(): array
    {
        return [
            ['key' => 'setup_completed', 'label' => 'Setup Completed'],
            ['key' => 'payment_due', 'label' => 'Payment Due'],
            ['key' => 'trial_ending', 'label' => 'Trial Ending'],
            ['key' => 'maintenance', 'label' => 'Maintenance'],
            ['key' => 'new_feature', 'label' => 'New Feature'],
            ['key' => 'urgent_notice', 'label' => 'Urgent Notice'],
        ];
    }
}
