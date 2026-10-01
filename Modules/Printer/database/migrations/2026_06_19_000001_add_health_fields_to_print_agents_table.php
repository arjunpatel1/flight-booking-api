<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('print_agents', function (Blueprint $table) {
            if (!Schema::hasColumn('print_agents', 'status')) {
                $table->string('status', 30)->default('offline')->after('last_seen_at')->index();
            }

            if (!Schema::hasColumn('print_agents', 'version')) {
                $table->string('version', 50)->nullable()->after('status');
            }

            if (!Schema::hasColumn('print_agents', 'platform')) {
                $table->string('platform', 100)->nullable()->after('version');
            }

            if (!Schema::hasColumn('print_agents', 'machine_name')) {
                $table->string('machine_name', 120)->nullable()->after('platform');
            }

            if (!Schema::hasColumn('print_agents', 'queue_status')) {
                $table->json('queue_status')->nullable()->after('machine_name');
            }

            if (!Schema::hasColumn('print_agents', 'printer_inventory')) {
                $table->json('printer_inventory')->nullable()->after('queue_status');
            }

            if (!Schema::hasColumn('print_agents', 'health_payload')) {
                $table->json('health_payload')->nullable()->after('printer_inventory');
            }

            if (!Schema::hasColumn('print_agents', 'last_error')) {
                $table->text('last_error')->nullable()->after('health_payload');
            }

            if (!Schema::hasColumn('print_agents', 'last_print_success_at')) {
                $table->timestamp('last_print_success_at')->nullable()->after('last_error');
            }

            if (!Schema::hasColumn('print_agents', 'last_print_failed_at')) {
                $table->timestamp('last_print_failed_at')->nullable()->after('last_print_success_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_agents', function (Blueprint $table) {
            foreach ([
                'last_print_failed_at',
                'last_print_success_at',
                'last_error',
                'health_payload',
                'printer_inventory',
                'queue_status',
                'machine_name',
                'platform',
                'version',
                'status',
            ] as $column) {
                if (Schema::hasColumn('print_agents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
