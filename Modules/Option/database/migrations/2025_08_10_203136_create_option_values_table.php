<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Option\Models\Option;
use Modules\Support\Enums\PriceType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('option_values', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(Option::class)->constrained()->cascadeOnDelete();
            $table->json('label')->nullable();
            $table->decimal('price', 18, 4)->unsigned()->nullable();
            $table->enum('price_type', PriceType::values());
            $table->order();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_values');
    }
};
