<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Post-tenant lifecycle stage.
 *
 * A column rather than another table: a tenant has exactly one current stage,
 * and the transition history is already captured as SaasCustomerSuccessRecord
 * entries of type `lifecycle`. Adding a stages table would duplicate that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenants', 'lifecycle_stage')) {
                $table->string('lifecycle_stage', 32)->nullable()->after('settings');
                $table->timestamp('lifecycle_changed_at')->nullable()->after('lifecycle_stage');
                $table->index(['lifecycle_stage', 'lifecycle_changed_at'], 'tenants_lifecycle_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            if (Schema::hasColumn('tenants', 'lifecycle_stage')) {
                $table->dropIndex('tenants_lifecycle_idx');
                $table->dropColumn(['lifecycle_stage', 'lifecycle_changed_at']);
            }
        });
    }
};
