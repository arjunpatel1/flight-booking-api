<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->index();
        });

        // Historical creator identity does not prove the recipient tenant.
        // Keep old rows unassigned rather than exposing a cross-tenant log.
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table): void {
            $table->dropColumn('tenant_id');
        });
    }
};
