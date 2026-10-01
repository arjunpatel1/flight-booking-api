<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_offline_orders', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();
            $table->foreignId('pos_register_id')->nullable()->constrained('pos_registers')->nullOnDelete();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->string('offline_id')->unique();
            $table->string('client_request_id')->nullable()->unique();
            $table->string('device_id')->nullable();
            $table->string('reference_no')->index();
            $table->string('order_number')->nullable();
            $table->string('status')->default('pending');
            $table->string('sync_status')->default('pending');
            $table->string('type');
            $table->string('currency', 3)->default('JOD');
            $table->decimal('currency_rate', 18, 6)->unsigned()->default(1);
            $table->decimal('subtotal', 18, 4)->unsigned()->default(0);
            $table->decimal('tax_amount', 18, 4)->unsigned()->default(0);
            $table->decimal('total', 18, 4)->unsigned()->default(0);
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('locked_at')->nullable();
            $table->dateTime('last_sync_attempt_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'sync_status', 'created_at']);
            $table->index(['pos_register_id', 'sync_status']);
            $table->index(['pos_session_id', 'sync_status']);
            $table->index(['device_id', 'sync_status']);
            $table->index(['sync_status', 'locked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_offline_orders');
    }
};
