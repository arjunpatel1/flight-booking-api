<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\SeatingPlan\Enums\TableShape;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Zone;
use Modules\User\Models\User;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(Floor::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Zone::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, "assigned_waiter_id")->nullable()->constrained("users")->nullOnDelete();
            $table->json('name');
            $table->unsignedInteger('capacity')->default(1);
            $table->enum('status', TableStatus::values())->default(TableStatus::Available->value);
            $table->enum('shape', TableShape::values())->default(TableShape::Square->value);
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
        Schema::dropIfExists('tables');
    }
};
