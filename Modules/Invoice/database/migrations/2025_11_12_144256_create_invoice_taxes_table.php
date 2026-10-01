<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Models\InvoiceLine;
use Modules\Tax\Enums\TaxType;
use Modules\Tax\Models\Tax;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Invoice::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(InvoiceLine::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Tax::class)->nullable()->constrained()->nullOnDelete();
            $table->json('name');
            $table->string('code');
            $table->decimal('rate', 8, 4);
            $table->string('currency', 3);
            $table->decimal('currency_rate', 18, 4)->default(1);
            $table->decimal('amount', 18, 4);
            $table->enum('type', TaxType::values());
            $table->boolean('compound')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['invoice_id', 'invoice_line_id']);
            $table->unique(['invoice_id', 'invoice_line_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_taxes');
    }
};
