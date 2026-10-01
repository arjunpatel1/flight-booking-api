<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Models\LoyaltyTier;
use Modules\User\Models\User;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class, "customer_id")->constrained("users")->cascadeOnDelete();
            $table->foreignIdFor(LoyaltyProgram::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(LoyaltyTier::class)->nullable()->constrained()->nullOnDelete();
            $table->integer('points_balance')->default(0);
            $table->integer('lifetime_points')->default(0);
            $table->dateTime('last_earned_at')->nullable();
            $table->dateTime('last_redeemed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_customers');
    }
};
