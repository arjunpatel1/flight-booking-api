<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\Unit;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->json("name");
            $table->foreignIdFor(Unit::class)->constrained()->cascadeOnDelete();
            $table->decimal('cost_per_unit', 18, 4)->unsigned()->default(0);
            $table->decimal('alert_quantity')->unsigned()->default(0);
            $table->decimal('current_stock', 12, 4)->default(0);
            $table->boolean("is_returnable")->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
