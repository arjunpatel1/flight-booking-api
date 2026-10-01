<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Loyalty\Enums\LoyaltyRewardType;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyTier;
use Modules\Support\Enums\PriceType;


return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->foreignIdFor(LoyaltyProgram::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(LoyaltyTier::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->json('name');
            $table->json('description')->nullable();
            $table->enum('type', LoyaltyRewardType::values());
            $table->unsignedInteger('points_cost');
            $table->string('currency', 3)->nullable();
            $table->decimal('value', 18, 4)->unsigned()->nullable();
            $table->enum('value_type', PriceType::values())->default(PriceType::Fixed->value);
            $table->unsignedInteger('max_redemptions_per_order')->default(1);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->json('conditions')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedInteger('total_redeemed')->default(0);
            $table->unsignedInteger('total_customers')->default(0);
            $table->active();
            $table->order();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
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
        Schema::dropIfExists('loyalty_rewards');
    }
};
