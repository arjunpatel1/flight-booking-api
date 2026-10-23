<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Notification Deliveries Table
        Schema::create('flight_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('event', 64)->index();
            $table->string('channel', 32)->index(); // email, sms, whatsapp
            $table->string('recipient', 255)->index();
            $table->string('template', 64);
            $table->string('status', 32)->default('PENDING')->index(); // PENDING, QUEUED, SENT, DELIVERED, FAILED, RETRYING
            $table->string('provider', 64)->nullable()->index(); // smtp, twilio, msg91, meta, mock
            $table->string('provider_reference', 255)->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 255)->nullable()->index();
            $table->timestamps();

            $table->foreign('booking_id')
                ->references('id')
                ->on('flight_bookings')
                ->onDelete('set null');
        });

        // 2. Incoming Webhook Events Table
        Schema::create('flight_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('provider', 64)->index(); // razorpay, stripe, twilio, msg91, amadeus, mock_gateway
            $table->string('event_id', 255);
            $table->string('event_type', 128)->index();
            $table->boolean('signature_valid')->default(false);
            $table->json('payload');
            $table->string('status', 32)->default('PENDING')->index(); // PENDING, PROCESSED, FAILED, IGNORED
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Strict duplicate event protection at DB level
            $table->unique(['provider', 'event_id'], 'flight_webhooks_provider_event_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_webhook_events');
        Schema::dropIfExists('flight_notification_deliveries');
    }
};
