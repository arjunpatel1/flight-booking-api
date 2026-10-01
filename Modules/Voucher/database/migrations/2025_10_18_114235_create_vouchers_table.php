<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Support\Enums\PriceType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->branch();
            $table->createdBy();
            $table->json('name');
            $table->string('code')->unique();
            $table->json('description')->nullable();
            $table->enum('type', PriceType::values());
            $table->decimal('value', 18, 4)->default(0);
            $table->json('conditions')->nullable();
            $table->decimal('minimum_spend', 18, 4)->unsigned()->nullable();
            $table->decimal('maximum_spend', 18, 4)->unsigned()->nullable();
            $table->integer('usage_limit')->unsigned()->nullable();
            $table->integer('per_customer_limit')->unsigned()->nullable();
            $table->integer('used')->default(0);
            $table->json('meta')->nullable();
            $table->active();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
