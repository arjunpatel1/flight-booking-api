<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Loyalty\Models\LoyaltyProgram;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->foreignIdFor(LoyaltyProgram::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->json('name');
            $table->decimal('min_spend', 18, 4)->default(0);
            $table->decimal('multiplier', 5)->default(1.00);
            $table->json('benefits')->nullable();
            $table->order();
            $table->active();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
