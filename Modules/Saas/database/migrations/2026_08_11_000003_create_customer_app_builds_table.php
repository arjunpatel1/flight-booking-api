<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_app_builds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_app_registration_id')->constrained('customer_app_registrations')->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('build_type', 16);
            $table->string('requested_version', 64);
            $table->string('status', 16)->default('queued');
            $table->string('source_commit', 64)->nullable();
            $table->unsignedBigInteger('branding_revision');
            $table->char('build_config_revision', 64);
            $table->char('request_fingerprint', 64);
            $table->char('active_fingerprint', 64)->nullable()->unique();
            $table->json('config_snapshot');
            $table->string('artifact_checksum', 128)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('testing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at'], 'customer_app_build_tenant_status_idx');
            $table->index(['customer_app_registration_id', 'request_fingerprint'], 'customer_app_build_registration_request_idx');
            $table->unique(['id', 'tenant_id'], 'customer_app_build_tenant_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_app_builds');
    }
};
