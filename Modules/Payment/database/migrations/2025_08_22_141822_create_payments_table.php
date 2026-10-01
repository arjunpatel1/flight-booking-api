<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Models\Order;
use Modules\Payment\Enums\PaymentMethod;
use Modules\User\Models\User;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->branch();
            $table->createdBy();
            $table->foreignIdFor(Order::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_reference_no');
            $table->string("transaction_id")->nullable()
                ->comment("transaction id from gateway, receipt no, etc");
            $table->enum('method', PaymentMethod::values());
            $table->decimal('amount', 18, 3);
            $table->string('currency', 3);
            $table->decimal('currency_rate', 18, 3)->default(1);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
