<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Loyalty\Enums\LoyaltyGiftStatus;
use Modules\Loyalty\Enums\LoyaltyRewardType;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyReward;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(LoyaltyCustomer::class)
                ->constrained()->cascadeOnDelete();
            $table->foreignIdFor(LoyaltyProgram::class)
                ->constrained()->cascadeOnDelete();
            $table->foreignIdFor(LoyaltyReward::class)
                ->constrained()->cascadeOnDelete();
            $table->nullableMorphs('artifact');
            $table->enum('type', LoyaltyRewardType::values());
            $table->enum('status', LoyaltyGiftStatus::values())->default(LoyaltyGiftStatus::Available->value);
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedBigInteger('points_spent');
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->dateTime('used_at')->nullable();
            $table->json('conditions')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['loyalty_customer_id', 'status']);
            $table->index(['valid_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_gifts');
    }
};
