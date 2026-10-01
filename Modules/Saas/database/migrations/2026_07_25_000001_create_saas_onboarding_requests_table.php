<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The onboarding request is the record of intent that sits between "a customer
 * wants NexDine" and "a tenant exists".
 *
 * It exists specifically so payment webhooks never call provisioning. A webhook
 * only ever advances *this* row; the row's approval mode then decides whether
 * provisioning is dispatched automatically or waits for a human. That keeps a
 * gateway retry, a duplicate event or a malformed payload from creating
 * tenants nobody can account for.
 *
 * Payment fields live here rather than on saas_billing_invoices because an
 * invoice requires a tenant_subscription, which by definition does not exist
 * yet. Once the tenant is provisioned, normal billing takes over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_onboarding_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Where the request came from: self_service, sales, admin, payment.
            $table->string('source', 32)->default('self_service');
            $table->string('approval_mode', 24)->default('manual');
            $table->string('status', 32)->default('draft');

            // Restaurant + owner details captured before the tenant exists.
            $table->string('restaurant_name');
            $table->string('slug', 120)->nullable();
            $table->string('domain')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->json('payload')->nullable();

            $table->string('plan_code', 60)->nullable();
            $table->unsignedInteger('trial_days')->nullable();

            // Pre-tenant payment capture.
            $table->string('payment_status', 24)->default('not_required');
            $table->string('payment_gateway', 32)->nullable();
            $table->string('payment_reference')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->timestamp('paid_at')->nullable();

            // Approval trail.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_reason')->nullable();

            // Outcome.
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('provisioning_run_id')->nullable()
                ->constrained('saas_provisioning_runs')->nullOnDelete();
            $table->foreignId('onboarding_invite_id')->nullable()
                ->constrained('saas_onboarding_invites')->nullOnDelete();

            // Failure handling — every failure needs a retry path and a reason.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->json('timeline')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['payment_status', 'status']);
            $table->index('email');
            // Webhook lookup path.
            $table->index(['payment_gateway', 'payment_reference'], 'saas_onboarding_payment_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_onboarding_requests');
    }
};
