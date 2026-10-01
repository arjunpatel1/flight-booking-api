<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Option\Models\Option;
use Modules\Order\Models\OrderProduct;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(OrderProduct::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Option::class)->constrained()->cascadeOnDelete();
            $table->text('value')->nullable();
            
            $table->unique(['order_product_id', 'option_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_product_options');
    }
};
