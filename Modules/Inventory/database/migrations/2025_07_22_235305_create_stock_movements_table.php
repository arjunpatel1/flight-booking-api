<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Models\Ingredient;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->nullableMorphs("source");
            $table->foreignIdFor(Ingredient::class)->constrained()->cascadeOnDelete();
            $table->enum("type", StockMovementType::values());
            $table->decimal('quantity', 18, 4)->unsigned();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
