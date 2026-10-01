<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\SeatingPlan\Enums\ReservationStatus;
use Modules\SeatingPlan\Enums\ReservationType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('table_reservations', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->string('reference_no')->unique();
            $table->enum('booking_type', ReservationType::values())->default(ReservationType::Table->value)->index();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();
            $table->string('hall_name')->nullable();
            $table->string('event_title')->nullable();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable()->index();
            $table->string('customer_email')->nullable();
            $table->unsignedSmallInteger('guest_count')->default(1)->index();
            $table->date('reservation_date')->index();
            $table->time('reservation_time');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->decimal('deposit_amount', 12, 4)->default(0);
            $table->enum('status', ReservationStatus::values())->default(ReservationStatus::Pending->value)->index();
            $table->text('special_requests')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'reservation_date', 'status'], 'table_reservations_branch_date_status_index');
            $table->index(['table_id', 'reservation_date', 'reservation_time'], 'table_reservations_table_date_time_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_reservations');
    }
};
