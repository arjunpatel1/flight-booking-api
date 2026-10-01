<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'tenant_id')) {
            return;
        }

        // This migration repairs legacy MySQL rows with join-update syntax.
        // A fresh SQLite test database has no legacy ownership data to repair.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // A branch is authoritative ownership evidence.
        DB::statement(
            'UPDATE users u
             INNER JOIN branches b ON b.id = u.branch_id
             SET u.tenant_id = b.tenant_id
             WHERE u.tenant_id IS NULL AND b.tenant_id IS NOT NULL'
        );

        // Tenant administrators created these records. Their ownership is safe
        // to inherit when an older create-user request omitted tenant_id.
        DB::statement(
            'UPDATE users u
             INNER JOIN users creator ON creator.id = u.created_by
             SET u.tenant_id = creator.tenant_id
             WHERE u.tenant_id IS NULL AND creator.tenant_id IS NOT NULL'
        );

        // Operational roles require a branch. Repair only unambiguous tenants
        // with a designated main branch; enterprise admins remain tenant-wide.
        DB::statement(
            "UPDATE users u
             INNER JOIN model_has_roles mhr
                ON mhr.model_id = u.id
               AND mhr.model_type = 'Modules\\\\User\\\\Models\\\\User'
             INNER JOIN roles r ON r.id = mhr.role_id
             INNER JOIN branches b
                ON b.tenant_id = u.tenant_id
               AND b.is_main = 1
               AND b.deleted_at IS NULL
             SET u.branch_id = b.id
             WHERE u.branch_id IS NULL
               AND u.tenant_id IS NOT NULL
               AND r.name IN ('admin_branch', 'manager', 'cashier', 'kitchen', 'waiter')"
        );
    }

    public function down(): void
    {
        // Ownership repair is intentionally irreversible. Clearing these
        // values would recreate unauthenticatable and cross-tenant records.
    }
};
