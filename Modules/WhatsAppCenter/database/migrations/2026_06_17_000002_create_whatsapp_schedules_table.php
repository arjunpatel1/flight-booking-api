<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('report_type', 100);
            $table->string('template_name', 100);
            $table->enum('frequency', ['daily', 'weekly', 'monthly'])->default('daily');
            $table->string('run_at', 5)->default('08:00');
            $table->tinyInteger('day_of_week')->nullable();
            $table->tinyInteger('day_of_month')->nullable();
            $table->json('recipients')->nullable();
            $table->json('filters')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_schedules');
    }
};
