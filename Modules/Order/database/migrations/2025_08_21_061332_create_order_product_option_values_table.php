<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Option\Models\OptionValue;
use Modules\Order\Models\OrderProductOption;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_product_option_values', function (Blueprint $table) {
            $table->foreignIdFor(OrderProductOption::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(OptionValue::class)->constrained()->cascadeOnDelete();
            
            $table->string('currency', 3)->nullable();
            $table->decimal('currency_rate', 18, 4)->nullable();
            $table->decimal('price', 18, 4)->unsigned()->nullable();

            $table->primary(['order_product_option_id', 'option_value_id'], 'order_product_option_id_option_value_id_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_product_option_values');
    }
};
