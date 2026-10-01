<?php

namespace Modules\Saas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Saas\Services\Tenant\TenantOwnershipClassifier;
use Throwable;

class AuditTenantOwnershipCommand extends Command
{
    protected $signature = 'saas:audit-tenant-ownership
        {--json : Emit machine-readable JSON}
        {--summary : Emit only the database audit summary}
        {--fail-on-violations : Return a failure code when orphaned or mismatched ownership exists}
        {--repair : Repair only deterministic tenant/branch mismatches; orphaned rows remain untouched}
        {--backup-reference= : Required immutable backup identifier for repair mode}
        {--reason= : Required audited reason for repair mode}
        {--tenant= : Restrict deterministic repairs to one tenant ID}';

    protected $description = 'Classify database tables and detect orphaned or cross-tenant ownership.';

    public function handle(TenantOwnershipClassifier $classifier): int
    {
        $database = DB::connection()->getDatabaseName();
        $rows = collect(Schema::getTables())
            ->filter(function (array $table) use ($database): bool {
                $schema = $table['schema'] ?? $table['table_schema'] ?? null;

                return $schema === null || $schema === $database;
            })
            ->map(fn (array $table) => $table['name'] ?? $table['table_name'] ?? null)
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $table) => $this->auditTable($table, $classifier))
            ->values();

        $linkedViolations = $this->linkedOwnershipViolations();
        $violations = $rows->sum(fn (array $row) => $row['orphaned_tenant']
            + $row['orphaned_branch']
            + $row['tenant_branch_mismatch']) + collect($linkedViolations)->sum('count');

        $payload = [
            'tables' => $rows,
            'linked_ownership_violations' => $linkedViolations,
            'summary' => [
                'tables' => $rows->count(),
                'tenant_owned' => $rows->whereIn('ownership', ['tenant', 'tenant_branch'])->count(),
                'branch_owned' => $rows->whereIn('ownership', ['branch', 'tenant_branch'])->count(),
                'global_or_reference' => $rows->where('ownership', 'global_or_reference')->count(),
                'violations' => $violations,
            ],
        ];

        if ($this->option('repair')) {
            $payload['repair'] = $this->repairDeterministicMismatches($rows, $classifier, $payload['summary']);
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($this->option('summary')) {
            $this->components->info(sprintf(
                'Audited %d table(s) in database "%s": %d violation(s).',
                $payload['summary']['tables'],
                $database,
                $violations,
            ));
        } else {
            $this->table(
                ['Table', 'Ownership', 'Tenant orphans', 'Branch orphans', 'Tenant/branch mismatch'],
                $rows->map(fn (array $row) => [
                    $row['table'],
                    $row['ownership'],
                    $row['orphaned_tenant'],
                    $row['orphaned_branch'],
                    $row['tenant_branch_mismatch'],
                ])->all(),
            );
            $this->components->info("Ownership audit completed with {$violations} violation(s).");
        }

        return $this->option('fail-on-violations') && $violations > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function linkedOwnershipViolations(): array
    {
        $checks = [
            ['key' => 'billing_invoice_subscription', 'tables' => ['saas_billing_invoices', 'tenant_subscriptions'], 'sql' => fn () => DB::table('saas_billing_invoices as child')->join('tenant_subscriptions as owner', 'owner.id', '=', 'child.tenant_subscription_id')->whereNotNull('child.tenant_subscription_id')->whereColumn('child.tenant_id', '!=', 'owner.tenant_id')->count()],
            ['key' => 'customer_build_registration', 'tables' => ['customer_app_builds', 'customer_app_registrations'], 'sql' => fn () => DB::table('customer_app_builds as child')->join('customer_app_registrations as owner', 'owner.id', '=', 'child.customer_app_registration_id')->whereColumn('child.tenant_id', '!=', 'owner.tenant_id')->count()],
            ['key' => 'customer_session_registration', 'tables' => ['customer_app_sessions', 'customer_app_registrations'], 'sql' => fn () => DB::table('customer_app_sessions as child')->join('customer_app_registrations as owner', 'owner.id', '=', 'child.customer_app_registration_id')->whereColumn('child.tenant_id', '!=', 'owner.tenant_id')->count()],
            ['key' => 'group_cart_branch', 'tables' => ['customer_group_carts', 'branches'], 'sql' => fn () => DB::table('customer_group_carts as child')->join('branches as owner', 'owner.id', '=', 'child.branch_id')->whereColumn('child.tenant_id', '!=', 'owner.tenant_id')->count()],
            ['key' => 'group_participant_customer', 'tables' => ['customer_group_participants', 'users'], 'sql' => fn () => DB::table('customer_group_participants as child')->join('users as owner', 'owner.id', '=', 'child.customer_id')->whereColumn('child.tenant_id', '!=', 'owner.tenant_id')->count()],
            ['key' => 'group_item_participant', 'tables' => ['customer_group_cart_items', 'customer_group_participants'], 'sql' => fn () => DB::table('customer_group_cart_items as child')->join('customer_group_participants as owner', 'owner.id', '=', 'child.participant_id')->whereColumn('child.tenant_id', '!=', 'owner.tenant_id')->count()],
        ];

        return collect($checks)->filter(fn ($check) => collect($check['tables'])->every(fn ($table) => Schema::hasTable($table)))
            ->map(fn ($check) => ['key' => $check['key'], 'count' => (int) $check['sql']()])->values()->all();
    }

    private function repairDeterministicMismatches($rows, TenantOwnershipClassifier $classifier, array $beforeSummary): array
    {
        $backup = trim((string) $this->option('backup-reference'));
        $reason = trim((string) $this->option('reason'));
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        if ($backup === '' || strlen($reason) < 10) {
            throw new \InvalidArgumentException('Repair mode requires --backup-reference and a --reason of at least 10 characters.');
        }
        if (! Schema::hasTable('tenant_integrity_repair_runs')) {
            throw new \RuntimeException('Run migrations before using tenant ownership repair mode.');
        }

        $runId = DB::table('tenant_integrity_repair_runs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'backup_reference' => $backup,
            'reason' => $reason,
            'status' => 'running',
            'before_summary' => json_encode($beforeSummary),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $changes = DB::transaction(function () use ($rows, $tenantId): array {
                $changes = [];
                foreach ($rows->where('ownership', 'tenant_branch')->where('tenant_branch_mismatch', '>', 0) as $row) {
                    $table = $row['table'];
                    if (in_array($table, ['branches', 'tenant_integrity_repair_runs'], true)) continue;

                    $query = DB::table("{$table} as owned")
                        ->join('branches as branch_owner', 'branch_owner.id', '=', 'owned.branch_id')
                        ->whereColumn('owned.tenant_id', '!=', 'branch_owner.tenant_id')
                        ->when($tenantId, fn ($builder) => $builder->where('branch_owner.tenant_id', $tenantId))
                        ->select('owned.id', 'branch_owner.tenant_id as correct_tenant_id');
                    $changed = 0;
                    foreach ($query->cursor() as $record) {
                        $changed += DB::table($table)->where('id', $record->id)->update([
                            'tenant_id' => $record->correct_tenant_id,
                        ...(Schema::hasColumn($table, 'updated_at') ? ['updated_at' => now()] : []),
                        ]);
                    }
                    if ($changed > 0) $changes[$table] = $changed;
                }
                return $changes;
            }, 3);

            $afterRows = collect(Schema::getTables())->map(fn (array $table) => $table['name'] ?? $table['table_name'] ?? null)
                ->filter()->unique()->map(fn (string $table) => $this->auditTable($table, $classifier));
            $after = ['violations' => $afterRows->sum(fn (array $row) => $row['orphaned_tenant'] + $row['orphaned_branch'] + $row['tenant_branch_mismatch'])];
            DB::table('tenant_integrity_repair_runs')->where('id', $runId)->update([
                'status' => 'completed', 'changes' => json_encode($changes), 'after_summary' => json_encode($after),
                'completed_at' => now(), 'updated_at' => now(),
            ]);
            return ['status' => 'completed', 'changes' => $changes, 'remaining_violations' => $after['violations']];
        } catch (Throwable $exception) {
            DB::table('tenant_integrity_repair_runs')->where('id', $runId)->update([
                'status' => 'failed', 'error' => Str::limit($exception->getMessage(), 4000), 'updated_at' => now(),
            ]);
            throw $exception;
        }
    }

    private function auditTable(string $table, TenantOwnershipClassifier $classifier): array
    {
        $columns = Schema::getColumns($table);
        $ownership = $classifier->classify($columns);
        $hasTenant = in_array($ownership, ['tenant', 'tenant_branch'], true);
        $hasBranch = in_array($ownership, ['branch', 'tenant_branch'], true);

        try {
            $orphanedTenant = $hasTenant && $table !== 'tenants'
                ? DB::table("{$table} as owned")
                    ->leftJoin('tenants as tenant_owner', 'tenant_owner.id', '=', 'owned.tenant_id')
                    ->whereNotNull('owned.tenant_id')
                    ->whereNull('tenant_owner.id')
                    ->count()
                : 0;
            $orphanedBranch = $hasBranch && $table !== 'branches'
                ? DB::table("{$table} as owned")
                    ->leftJoin('branches as branch_owner', 'branch_owner.id', '=', 'owned.branch_id')
                    ->whereNotNull('owned.branch_id')
                    ->whereNull('branch_owner.id')
                    ->count()
                : 0;
            $mismatch = $hasTenant && $hasBranch
                ? DB::table("{$table} as owned")
                    ->join('branches as branch_owner', 'branch_owner.id', '=', 'owned.branch_id')
                    ->whereColumn('owned.tenant_id', '!=', 'branch_owner.tenant_id')
                    ->count()
                : 0;
        } catch (Throwable $exception) {
            $this->components->warn("Could not inspect {$table}: {$exception->getMessage()}");
            $orphanedTenant = $orphanedBranch = $mismatch = 0;
        }

        return [
            'table' => $table,
            'ownership' => $ownership,
            'orphaned_tenant' => $orphanedTenant,
            'orphaned_branch' => $orphanedBranch,
            'tenant_branch_mismatch' => $mismatch,
        ];
    }
}
