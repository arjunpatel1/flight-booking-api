<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tenant_service_assignments', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('feature', 80); $t->string('billing_mode', 30);
            $t->string('status', 30)->default('active');
            $t->foreignId('saas_billing_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamp('starts_at'); $t->timestamp('ends_at');
            $t->uuid('idempotency_key'); $t->string('reason', 500);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps(); $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'feature', 'status']);
        });
        Schema::create('tenant_service_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('tenant_service_assignment_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event', 40); $t->string('reason', 500); $t->timestamp('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('tenant_service_events'); Schema::dropIfExists('tenant_service_assignments'); }
};
