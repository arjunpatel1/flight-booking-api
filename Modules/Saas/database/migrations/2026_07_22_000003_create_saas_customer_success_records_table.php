<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_customer_success_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('visibility', 24)->default('internal');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('priority', 24)->default('normal');
            $table->string('status', 24)->default('open');
            $table->timestamp('due_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'status']);
            $table->index(['assigned_to', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_customer_success_records');
    }
};
