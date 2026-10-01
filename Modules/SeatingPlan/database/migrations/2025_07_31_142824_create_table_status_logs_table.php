<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\SeatingPlan\Enums\TableStatus;
use Modules\SeatingPlan\Models\Table;
use Modules\User\Models\User;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('table_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Table::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'changed_by')
                ->nullable()
                ->constrained("users")
                ->nullOnDelete();
            $table->enum('status', TableStatus::values())->default(TableStatus::Available->value);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(["changed_by", "status"]);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_status_logs');
    }
};
