<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Invoice\Models\Invoice;
use Modules\Order\Enums\DiscountType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Invoice::class)->constrained()->cascadeOnDelete();
            $table->nullableMorphs('discountable');
            $table->enum('source', DiscountType::values())->index();
            $table->json('name')->nullable();
            $table->string('currency');
            $table->decimal('currency_rate', 18, 4)->default(1);
            $table->decimal('amount', 18, 4)->default(0);
            $table->boolean('applied_before_tax')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['invoice_id', 'discountable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_discounts');
    }
};
