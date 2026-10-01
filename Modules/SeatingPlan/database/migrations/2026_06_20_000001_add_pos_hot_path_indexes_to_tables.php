<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Compound index for POS table screen hot-path: filters by branch + active + status on every table load.
        // Without this, MySQL can only use the single-column FK index on branch_id, then scans all branch rows
        // to filter status/is_active in memory — one full branch-scoped scan per waiter screen refresh.
        if (Schema::hasTable('tables') && !Schema::hasIndex('tables', 'idx_tables_branch_active_status')) {
            Schema::table('tables', function (Blueprint $table) {
                $table->index(['branch_id', 'is_active', 'status'], 'idx_tables_branch_active_status');
            });
        }

        // Soft-delete-aware index: queries that filter by zone or floor within a branch also skip deleted rows.
        if (Schema::hasTable('tables') && !Schema::hasIndex('tables', 'idx_tables_branch_floor_deleted')) {
            Schema::table('tables', function (Blueprint $table) {
                $table->index(['branch_id', 'floor_id', 'deleted_at'], 'idx_tables_branch_floor_deleted');
            });
        }
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table) {
            $table->dropIndexIfExists('idx_tables_branch_active_status');
            $table->dropIndexIfExists('idx_tables_branch_floor_deleted');
        });
    }
};
