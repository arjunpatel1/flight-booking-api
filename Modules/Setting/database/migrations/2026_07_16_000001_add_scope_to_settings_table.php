<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'tenant_id')) {
                $table->foreignId('tenant_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('tenants')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('settings', 'branch_id')) {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('tenant_id')
                    ->constrained('branches')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('settings', 'setting_scope')) {
                $table->string('setting_scope', 80)
                    ->default('global')
                    ->after('branch_id')
                    ->index();
            }
        });

        DB::table('settings')
            ->whereNull('setting_scope')
            ->orWhere('setting_scope', '')
            ->update(['setting_scope' => 'global']);

        try {
            Schema::table('settings', fn (Blueprint $table) => $table->dropUnique('settings_key_unique'));
        } catch (Throwable) {
            // Existing installations may already have the safer composite index.
        }

        try {
            Schema::table('settings', fn (Blueprint $table) => $table->unique(['setting_scope', 'key'], 'settings_scope_key_unique'));
        } catch (Throwable) {
            // Keep migration idempotent for partially migrated environments.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('settings', fn (Blueprint $table) => $table->dropUnique('settings_scope_key_unique'));
        } catch (Throwable) {
        }

        try {
            Schema::table('settings', fn (Blueprint $table) => $table->unique('key', 'settings_key_unique'));
        } catch (Throwable) {
        }

        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }

            if (Schema::hasColumn('settings', 'tenant_id')) {
                $table->dropConstrainedForeignId('tenant_id');
            }

            if (Schema::hasColumn('settings', 'setting_scope')) {
                $table->dropColumn('setting_scope');
            }
        });
    }
};
