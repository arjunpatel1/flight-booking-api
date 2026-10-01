<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\SeatingPlan\Enums\TableMergeType;
use Modules\SeatingPlan\Models\Table;
use Modules\User\Models\User;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('table_merges', function (Blueprint $table) {
            $table->id();
            $table->createdBy();
            $table->branch();
            $table->foreignIdFor(Table::class)
                ->constrained('tables')
                ->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'closed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->enum('type', TableMergeType::values());
            $table->text('notes')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'table_merge_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('table_merge_id')
                    ->references('id')
                    ->on('table_merges')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'table_merge_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['table_merge_id']);
            });
        }

        Schema::dropIfExists('table_merges');
    }
};
