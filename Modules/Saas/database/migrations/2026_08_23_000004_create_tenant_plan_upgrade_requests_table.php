<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('tenant_plan_upgrade_requests')) {
            Schema::create('tenant_plan_upgrade_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('current_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('requested_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('contact_name', 120);
            $table->string('contact_email', 160);
            $table->string('contact_phone', 30)->nullable();
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'created_at'], 'tenant_upgrade_owner_status_idx');
            $table->index(['tenant_id', 'requested_plan_id', 'status'], 'tenant_upgrade_plan_status_idx');
            });
        }

        if (! Schema::hasTable('tenant_plan_upgrade_request_events')) {
            Schema::create('tenant_plan_upgrade_request_events', function (Blueprint $table) {
            $table->id();
            // The auto-generated constraint name for this column is 73 chars,
            // over MySQL's 64-char identifier limit, which aborted migrate:fresh
            // for the whole suite. Name it explicitly.
            $table->foreignId('tenant_plan_upgrade_request_id')
                ->constrained('tenant_plan_upgrade_requests', indexName: 'tenant_upgrade_event_request_fk')
                ->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_plan_upgrade_request_id', 'created_at'], 'tenant_upgrade_event_history_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_plan_upgrade_request_events');
        Schema::dropIfExists('tenant_plan_upgrade_requests');
    }
};
