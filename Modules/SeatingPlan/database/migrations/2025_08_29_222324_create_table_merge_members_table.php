<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\SeatingPlan\Models\Table;
use Modules\SeatingPlan\Models\TableMerge;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('table_merge_members', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(TableMerge::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Table::class)->constrained()->cascadeOnDelete();
            $table->boolean("is_main")->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_merge_members');
    }
};
