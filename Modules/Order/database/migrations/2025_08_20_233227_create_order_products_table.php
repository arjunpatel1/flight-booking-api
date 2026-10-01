<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Models\Order;
use Modules\Product\Models\Product;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_products', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->foreignIdFor(Order::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Product::class)->constrained()->cascadeOnDelete();
            $table->string('currency', 3);
            $table->decimal('currency_rate', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->comment("Unit price without tax");
            $table->integer('quantity');
            $table->decimal('subtotal', 18, 4)->unsigned()->comment("unit_price * quantity without tax");
            $table->decimal('tax_total', 18, 4)->unsigned()->comment("Total tax amount for this product");
            $table->decimal('total', 18, 4)->unsigned()->comment("subtotal + tax_total");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_products');
    }
};
