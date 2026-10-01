<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a remote control plane to POS terminal devices so a compromised or
 * decommissioned terminal can be disabled (forcing logout on its next
 * heartbeat) and the action is audited. The heartbeat response becomes the
 * delivery channel for these directives.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('pos_terminal_devices')) {
            return;
        }

        Schema::table('pos_terminal_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_terminal_devices', 'is_disabled')) {
                $table->boolean('is_disabled')->default(false)->after('status');
            }
            if (! Schema::hasColumn('pos_terminal_devices', 'disabled_at')) {
                $table->timestamp('disabled_at')->nullable()->after('is_disabled');
            }
            if (! Schema::hasColumn('pos_terminal_devices', 'disabled_reason')) {
                $table->string('disabled_reason')->nullable()->after('disabled_at');
            }
            if (! Schema::hasColumn('pos_terminal_devices', 'disabled_by')) {
                $table->foreignId('disabled_by')->nullable()->after('disabled_reason');
            }

            // Hot path: list/filter disabled terminals per branch.
            if (! Schema::hasIndex('pos_terminal_devices', 'pos_terminals_branch_disabled_idx')) {
                $table->index(['branch_id', 'is_disabled'], 'pos_terminals_branch_disabled_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_terminal_devices')) {
            return;
        }

        Schema::table('pos_terminal_devices', function (Blueprint $table) {
            if (Schema::hasIndex('pos_terminal_devices', 'pos_terminals_branch_disabled_idx')) {
                $table->dropIndex('pos_terminals_branch_disabled_idx');
            }

            $columns = array_values(array_filter(
                ['is_disabled', 'disabled_at', 'disabled_reason', 'disabled_by'],
                fn (string $column): bool => Schema::hasColumn('pos_terminal_devices', $column),
            ));

            if (! empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
