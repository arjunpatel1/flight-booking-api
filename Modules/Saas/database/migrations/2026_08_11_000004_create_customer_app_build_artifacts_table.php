<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_app_build_artifacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('customer_app_build_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('build_type', 16);
            $table->string('version', 64);
            $table->string('checksum', 128);
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_reference', 512);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at'], 'customer_app_artifact_tenant_revoked_idx');
            $table->foreign(['customer_app_build_id', 'tenant_id'], 'customer_app_artifact_build_owner_fk')
                ->references(['id', 'tenant_id'])->on('customer_app_builds')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_app_build_artifacts');
    }
};
