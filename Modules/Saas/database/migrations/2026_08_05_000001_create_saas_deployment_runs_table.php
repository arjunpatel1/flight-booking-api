<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saas_deployment_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('branch', 120);
            $table->boolean('apply')->default(false);
            $table->string('status', 24)->default('pending');
            $table->string('previous_revision', 64)->nullable();
            $table->string('new_revision', 64)->nullable();
            $table->longText('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['branch', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_deployment_runs');
    }
};
