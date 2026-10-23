<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flight_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('flight_booking_id')->index();
            $table->unsignedBigInteger('flight_fare_quote_id')->nullable()->index();
            $table->unsignedBigInteger('flight_hold_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('payer_type', 32)->default('CUSTOMER');
            $table->unsignedBigInteger('payer_id')->nullable();

            $table->string('payment_method', 32)->default('GATEWAY'); // GATEWAY, WALLET, UPI, etc.
            $table->string('gateway', 32)->default('razorpay');      // razorpay, mock, wallet
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('gateway_transaction_id')->nullable()->index();
            $table->string('payment_reference', 64)->unique();
            $table->string('idempotency_key', 128)->nullable()->index();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status', 32)->default('PENDING')->index(); // PENDING, INITIATED, AUTHORIZED, COMPLETED, FAILED, CANCELLED, REFUNDED

            $table->text('failure_reason')->nullable();
            $table->json('gateway_response_metadata')->nullable();
            $table->string('reconciliation_reference', 128)->nullable();

            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->decimal('refunded_amount', 12, 2)->default(0.00);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'idempotency_key']);
            $table->index(['gateway', 'gateway_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_payments');
    }
};
