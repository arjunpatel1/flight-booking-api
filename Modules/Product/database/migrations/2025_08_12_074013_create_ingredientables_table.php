<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\Ingredient;
use Modules\Product\Enums\IngredientOperation;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ingredientables', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(Ingredient::class)->constrained()->cascadeOnDelete();
            $table->morphs('ingredientable');
            $table->decimal('quantity', 10, 4);
            $table->enum('operation', IngredientOperation::values())->default(IngredientOperation::Add->value);
            $table->unsignedSmallInteger('priority')->default(10);
            $table->decimal('loss_pct', 5)->default(0);
            $table->string('note')->nullable();
            $table->order();
            $table->timestamps();

            $table->index(['ingredientable_type', 'ingredientable_id', 'order'], 'ingredientables_owner_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredientables');
    }
};
