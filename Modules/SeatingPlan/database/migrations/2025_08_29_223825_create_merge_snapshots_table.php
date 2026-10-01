<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\SeatingPlan\Models\TableMerge;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('merge_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(TableMerge::class)->constrained()->cascadeOnDelete();
            $table->json("snapshot");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merge_snapshots');
    }
};
