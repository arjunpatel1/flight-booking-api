<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Infrastructure Registry (Phase 1).
 *
 * Control-plane metadata describing the infrastructure each tenant WILL run on
 * once dedicated databases exist. It holds NO restaurant business data — only
 * where a tenant's database lives, its connection health, and the status of its
 * storage / redis / queue / reverb / backup.
 *
 * In Phase 1 this table is purely descriptive. Nothing in the request path
 * reads it, no connection is opened from it, and a tenant with no row here
 * simply continues on the shared database exactly as today. It becomes
 * authoritative only in a later phase, when the connection resolver is wired in.
 *
 * One row per tenant (`tenant_id` unique). Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saas_tenant_infrastructure')) {
            return;
        }

        Schema::create('saas_tenant_infrastructure', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();

            // shared | dedicated | cluster — how this tenant is hosted.
            $table->string('mode', 24)->default('shared');

            // --- Database ---
            $table->string('db_type', 32)->default('mysql');       // engine family
            $table->string('db_driver', 32)->default('mysql');     // laravel driver
            $table->string('db_host')->nullable();
            $table->unsignedSmallInteger('db_port')->nullable();
            $table->string('db_name')->nullable();
            $table->string('db_username')->nullable();
            // Encrypted at rest; unused in Phase 1 (no connection is opened).
            $table->text('db_password')->nullable();

            // --- Runtime resources (descriptive; not switched in Phase 1) ---
            $table->string('storage_disk', 60)->nullable();
            $table->string('redis_prefix')->nullable();
            $table->string('queue_name', 60)->nullable();
            $table->string('reverb_namespace')->nullable();

            // --- State ---
            // unconfigured | pending | connected | unreachable | error
            $table->string('connection_status', 24)->default('unconfigured');
            $table->string('migration_version')->nullable();
            // unknown | healthy | degraded | down
            $table->string('health_status', 24)->default('unknown');
            $table->timestamp('last_health_check_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();

            // --- Backup ---
            $table->string('backup_status', 24)->default('none');  // none | scheduled | ok | failed
            $table->timestamp('last_backup_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['mode', 'health_status']);
            $table->index('connection_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_tenant_infrastructure');
    }
};
