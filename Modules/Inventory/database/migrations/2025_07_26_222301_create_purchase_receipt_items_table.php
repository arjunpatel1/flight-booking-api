<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\PurchaseItem;
use Modules\Inventory\Models\PurchaseReceipt;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(PurchaseReceipt::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(PurchaseItem::class)->constrained()->cascadeOnDelete();
            $table->decimal('received_quantity', 10, 4)->unsigned();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_items');
    }
};
