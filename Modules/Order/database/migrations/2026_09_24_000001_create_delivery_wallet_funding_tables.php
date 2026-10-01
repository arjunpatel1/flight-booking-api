<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('delivery_wallet_funding_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 24);
            $table->string('label', 120);
            $table->json('details')->nullable();
            $table->string('qr_image_path', 500)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('delivery_wallet_top_up_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('funding_method_id')->nullable()->constrained('delivery_wallet_funding_methods')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 24);
            $table->string('status', 24)->default('pending');
            $table->char('currency', 3)->default('INR');
            $table->decimal('credit_amount', 18, 4);
            $table->decimal('gst_rate', 8, 4);
            $table->decimal('gst_amount', 18, 4);
            $table->decimal('payable_amount', 18, 4);
            $table->string('payment_reference', 191)->nullable();
            $table->string('proof_path', 500)->nullable();
            $table->string('gateway_order_id', 191)->nullable()->unique();
            $table->string('gateway_payment_id', 191)->nullable()->unique();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at'], 'delivery_wallet_topup_tenant_status');
            $table->index(['status', 'created_at'], 'delivery_wallet_topup_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_wallet_top_up_requests');
        Schema::dropIfExists('delivery_wallet_funding_methods');
    }
};
