<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\SeatingPlan\Models\Floor;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(Floor::class)->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->string('color')->nullable();
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
        Schema::dropIfExists('zones');
    }
};
