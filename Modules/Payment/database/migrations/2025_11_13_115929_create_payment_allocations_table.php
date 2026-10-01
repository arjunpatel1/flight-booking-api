<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Invoice\Models\Invoice;
use Modules\Payment\Models\Payment;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Payment::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(Invoice::class)
                ->constrained()
                ->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('currency_rate', 18, 4)->default(1);
            $table->decimal('amount', 18, 4);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['payment_id', 'invoice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
