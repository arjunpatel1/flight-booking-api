<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->index(
                ['status', 'completed_at'],
                'idempotency_status_completed_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex('idempotency_status_completed_idx');
        });
    }
};
