<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Invoice\Models\Invoice;
use Modules\Order\Models\OrderProduct;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Invoice::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(OrderProduct::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->json('description');
            $table->string('sku')->nullable();
            $table->decimal('unit_price', 18, 4);
            $table->decimal('quantity', 18, 4);
            $table->string('currency', 3);
            $table->decimal('currency_rate', 18, 4)->default(1);
            $table->decimal('tax_amount', 18, 4);
            $table->decimal('line_total_excl_tax', 18, 4);
            $table->decimal('line_total_incl_tax', 18, 4);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
