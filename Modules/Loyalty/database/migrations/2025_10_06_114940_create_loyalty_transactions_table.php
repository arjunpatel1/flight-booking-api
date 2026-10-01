<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Loyalty\Enums\LoyaltyTransactionType;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Order\Models\Order;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(LoyaltyCustomer::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Order::class)->nullable();
            $table->enum('type', LoyaltyTransactionType::values());
            $table->integer('points');
            $table->decimal('amount', 18, 4)->nullable();
            $table->json('meta')->nullable();
            $table->createdBy();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
