<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Enums\OrderPaymentStatus;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Enums\OrderType;
use Modules\Order\Models\Order;
use Modules\Pos\Models\PosRegister;
use Modules\Pos\Models\PosSession;
use Modules\SeatingPlan\Models\Table;
use Modules\User\Models\User;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(Order::class, 'merged_into_order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete();
            $table->foreignIdFor(Table::class)->nullable()->constrained()->nullOnDelete();
            if (Schema::hasTable('table_merges')) {
                $table->foreignId('table_merge_id')->nullable()->constrained('table_merges')->nullOnDelete();
            } else {
                $table->foreignId('table_merge_id')->nullable()->index();
            }
            $table->foreignIdFor(PosRegister::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(PosSession::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class, 'merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignIdFor(User::class, 'waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignIdFor(User::class, 'cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_no')->unique();
            $table->string('order_number');
            $table->enum('status', OrderStatus::values())->index();
            $table->enum('type', OrderType::values())->index();
            $table->enum('payment_status', OrderPaymentStatus::values());
            $table->string('currency', 3);
            $table->decimal('currency_rate', 18, 4)->default(1);
            $table->decimal('subtotal', 18, 4)->unsigned();
            $table->decimal('total', 18, 4)->unsigned();
            $table->integer('guest_count')->default(1);
            $table->text('notes')->nullable();
            $table->boolean('kitchen_display')->default(true);
            $table->boolean('is_rush')->default(false);
            $table->date('order_date');
            $table->boolean('is_stock_deducted')->default(false);
            $table->dateTime('served_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('merged_at')->nullable();
            $table->dateTime('payment_at')->nullable();
            $table->timestamps();

            $table->unique(['order_date', 'order_number', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
