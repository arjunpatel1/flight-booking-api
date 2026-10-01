<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('print_agents')) {
            return;
        }

        $missing = collect([
            'status',
            'version',
            'platform',
            'machine_name',
            'queue_status',
            'printer_inventory',
            'health_payload',
            'last_error',
            'last_print_success_at',
            'last_print_failed_at',
        ])->reject(fn (string $column) => Schema::hasColumn('print_agents', $column));

        if ($missing->isEmpty()) {
            return;
        }

        Schema::table('print_agents', function (Blueprint $table) use ($missing) {
            if ($missing->contains('status')) {
                $table->string('status', 30)->default('offline')->after('last_seen_at')->index();
            }
            if ($missing->contains('version')) {
                $table->string('version', 50)->nullable()->after('status');
            }
            if ($missing->contains('platform')) {
                $table->string('platform', 100)->nullable()->after('version');
            }
            if ($missing->contains('machine_name')) {
                $table->string('machine_name', 120)->nullable()->after('platform');
            }
            if ($missing->contains('queue_status')) {
                $table->json('queue_status')->nullable()->after('machine_name');
            }
            if ($missing->contains('printer_inventory')) {
                $table->json('printer_inventory')->nullable()->after('queue_status');
            }
            if ($missing->contains('health_payload')) {
                $table->json('health_payload')->nullable()->after('printer_inventory');
            }
            if ($missing->contains('last_error')) {
                $table->text('last_error')->nullable()->after('health_payload');
            }
            if ($missing->contains('last_print_success_at')) {
                $table->timestamp('last_print_success_at')->nullable()->after('last_error');
            }
            if ($missing->contains('last_print_failed_at')) {
                $table->timestamp('last_print_failed_at')->nullable()->after('last_print_success_at');
            }
        });
    }

    public function down(): void
    {
        // This is a forward-only schema repair. The original health migration
        // owns rollback of these columns.
    }
};
