<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saas_communication_templates', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('key')->unique(); $table->string('title'); $table->text('message');
            $table->json('channels')->nullable(); $table->string('priority', 20)->default('normal'); $table->boolean('is_active')->default(true); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
        Schema::create('saas_communication_campaigns', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->foreignId('template_id')->nullable()->constrained('saas_communication_templates')->nullOnDelete();
            $table->json('tenant_ids'); $table->string('title'); $table->text('message'); $table->json('channels'); $table->string('priority', 20)->default('normal');
            $table->string('status', 30)->default('scheduled')->index(); $table->timestamp('scheduled_at')->index(); $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); $table->text('error_message')->nullable();
            $table->unsignedInteger('delivery_count')->default(0); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('saas_communication_campaigns'); Schema::dropIfExists('saas_communication_templates'); }
};
