<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\Reason;
use Modules\User\Models\User;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Order::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'changed_by')
                ->nullable()
                ->constrained("users")
                ->nullOnDelete();
            $table->foreignIdFor(Reason::class)->nullable()->constrained()->nullOnDelete();
            $table->enum("status", OrderStatus::values());
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(["changed_by", "status", "order_id", "reason_id"], "cby_status_oid_crid");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_logs');
    }
};
