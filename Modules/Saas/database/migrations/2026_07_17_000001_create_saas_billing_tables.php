<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saas_billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained('tenant_subscriptions')->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status')->default('draft');
            $table->string('gateway')->nullable();
            $table->string('gateway_reference')->nullable();
            $table->string('payment_url', 1000)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['due_at', 'status']);
        });

        Schema::create('saas_billing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saas_billing_invoice_id')->constrained('saas_billing_invoices')->cascadeOnDelete();
            $table->string('type');
            $table->string('channel')->nullable();
            $table->string('status')->default('pending');
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['saas_billing_invoice_id', 'type']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_billing_events');
        Schema::dropIfExists('saas_billing_invoices');
    }
};
