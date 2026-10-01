<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Loyalty\Enums\LoyaltyPromotionType;
use Modules\Loyalty\Models\LoyaltyProgram;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_promotions', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->foreignIdFor(LoyaltyProgram::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->json('name');
            $table->json('description')->nullable();
            $table->enum('type', LoyaltyPromotionType::values());
            $table->decimal('multiplier')->nullable()->comment('e.g. 2.0 = double points');
            $table->unsignedInteger('bonus_points')->nullable()->comment('extra points to add');
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->json('conditions')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('total_used')->default(0);
            $table->unsignedInteger('total_customers')->default(0);
            $table->active();
            $table->order();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['loyalty_program_id', 'type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_promotions');
    }
};
