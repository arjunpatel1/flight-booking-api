<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Invoice\Enums\InvoiceKind;
use Modules\Invoice\Enums\InvoicePurpose;
use Modules\Invoice\Enums\InvoiceStatus;
use Modules\Invoice\Enums\InvoiceType;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Models\InvoiceParty;
use Modules\Order\Models\Order;
use Modules\SeatingPlan\Models\TableMerge;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(Order::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(TableMerge::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(InvoiceParty::class, "seller_party_id")
                ->constrained("invoice_parties")->restrictOnDelete();
            $table->foreignIdFor(InvoiceParty::class, "buyer_party_id")
                ->nullable()
                ->constrained("invoice_parties")
                ->nullOnDelete();
            $table->string('invoice_number', 80)->unique();
            $table->unsignedBigInteger('invoice_counter')->nullable();
            $table->uuid();
            $table->enum('type', InvoiceType::values())->default(InvoiceType::Simplified->value);
            $table->enum('purpose', InvoicePurpose::values())->default(InvoicePurpose::Original->value);
            $table->enum('invoice_kind', InvoiceKind::values())
                ->default(InvoiceKind::Standard->value);
            $table->foreignIdFor(Invoice::class, "reference_invoice_id")
                ->nullable()
                ->constrained("invoices")
                ->nullOnDelete();
            $table->string('currency', 3);
            $table->decimal('currency_rate', 18, 4)->default(1);
            $table->decimal('subtotal', 18, 4);
            $table->decimal('tax_total', 18, 4);
            $table->decimal('discount_total', 18, 4);
            $table->decimal('total', 18, 4);
            $table->decimal('rounding_adjustment', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->decimal('refunded_amount', 18, 4)->default(0);
            $table->decimal('net_paid', 18, 4)->default(0);

            $table->enum('status', InvoiceStatus::values())->default(InvoiceStatus::Issued->value);
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
