<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\User\Models\User;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Branch::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('report_key');
            $table->string('name');
            $table->string('frequency', 20)->default('daily');
            $table->time('run_at')->default('23:00:00');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->json('formats')->nullable();
            $table->json('recipients')->nullable();
            $table->json('filters')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'report_key', 'is_active'], 'report_schedules_branch_key_active_idx');
            $table->index(['user_id', 'created_at'], 'report_schedules_user_created_idx');
            $table->index(['is_active', 'next_run_at'], 'report_schedules_active_next_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
