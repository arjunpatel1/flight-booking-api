<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Enums\PurchaseStatus;
use Modules\Inventory\Models\Supplier;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->string("reference_number")->nullable();
            $table->foreignIdFor(Supplier::class)->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->string('currency', 3);
            $table->decimal('currency_rate', 18, 4)->default(1);
            $table->decimal('discount', 18, 4)->unsigned()->default(0);
            $table->decimal('tax', 18, 4)->unsigned()->default(0);
            $table->decimal('sub_total', 18, 4)->unsigned()->default(0);
            $table->decimal('total', 18, 4)->unsigned()->default(0);
            $table->enum("status", PurchaseStatus::values())->default(PurchaseStatus::Pending->value);
            $table->dateTime("expected_at")->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
