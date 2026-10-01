<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderProduct;
use Modules\Tax\Enums\TaxType;
use Modules\Tax\Models\Tax;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Order::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(OrderProduct::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Tax::class)->nullable()->constrained()->nullOnDelete();
            $table->json('name');
            $table->decimal('rate', 8, 4);
            $table->string('currency', 3);
            $table->decimal('currency_rate', 18, 4)->default(1);
            $table->decimal('amount', 18, 4);
            $table->enum('type', TaxType::values());
            $table->boolean('compound')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_taxes');
    }
};
