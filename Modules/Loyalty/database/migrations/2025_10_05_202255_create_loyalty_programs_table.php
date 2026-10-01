<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->json('name');
            $table->decimal('earning_rate', 10, 4)->unsigned()->default(1);
            $table->decimal('redemption_rate', 10, 4)->unsigned()->default(0.01);
            $table->unsignedInteger('min_redeem_points')->default(100);
            $table->unsignedInteger('points_expire_after')->nullable();
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
        Schema::dropIfExists('loyalty_programs');
    }
};
