<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_app_content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('title', 160);
            $table->string('subtitle', 255)->nullable();
            $table->text('body')->nullable();
            $table->string('image_path')->nullable();
            $table->string('mobile_image_path')->nullable();
            $table->string('cta_action', 40)->nullable();
            $table->string('cta_label', 80)->nullable();
            $table->string('cta_target', 2048)->nullable();
            $table->string('linked_resource_type', 80)->nullable();
            $table->unsignedBigInteger('linked_resource_id')->nullable();
            $table->string('status', 24)->default('draft');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type', 'status', 'is_active', 'display_order'], 'customer_app_content_runtime_idx');
            $table->index(['tenant_id', 'status', 'starts_at', 'ends_at'], 'customer_app_content_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_app_content_items');
    }
};
