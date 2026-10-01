<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Pricing\Enums\PriceTypeRuleType;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('price_types')) {
            return;
        }

        Schema::create('price_types', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->json('name');
            $table->string('code', 50)->unique();
            $table->enum('rule_type', PriceTypeRuleType::values())->index();
            $table->decimal('rule_value', 12, 4);
            $table->json('description')->nullable();
            $table->active();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'rule_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_types');
    }
};
