<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('partner_api_integrations')) Schema::create('partner_api_integrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 160);
            $table->string('environment', 16)->default('production');
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });

        if (! Schema::hasTable('partner_api_credentials')) Schema::create('partner_api_credentials', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('api_key', 80)->unique();
            $table->string('secret_fingerprint', 64);
            $table->text('secret_ciphertext');
            $table->json('scopes');
            $table->json('branch_ids')->nullable();
            $table->json('ip_allowlist')->nullable();
            $table->unsignedInteger('requests_per_minute')->default(120);
            $table->unsignedInteger('orders_per_minute')->default(30);
            $table->unsignedInteger('burst_limit')->default(30);
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('rotated_from_id')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['partner_id', 'status']);
        });

        if (! Schema::hasTable('partner_api_resource_mappings')) Schema::create('partner_api_resource_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('resource_type', 30);
            $table->unsignedBigInteger('resource_id');
            $table->string('external_reference', 160);
            $table->timestamps();
            $table->unique(['partner_id', 'resource_type', 'external_reference'], 'partner_resource_external_unique');
            $table->unique(['partner_id', 'resource_type', 'resource_id'], 'partner_resource_internal_unique');
            $table->index(['tenant_id', 'resource_type', 'resource_id'], 'partner_resource_tenant_lookup_idx');
        });

        // MySQL does not roll back CREATE TABLE when a later index ALTER fails.
        // Repair the partially-created table before continuing the migration.
        if (! Schema::hasIndex('partner_api_resource_mappings', 'partner_resource_tenant_lookup_idx')) {
            Schema::table('partner_api_resource_mappings', function (Blueprint $table) {
                $table->index(['tenant_id', 'resource_type', 'resource_id'], 'partner_resource_tenant_lookup_idx');
            });
        }

        if (! Schema::hasTable('partner_api_order_mappings')) Schema::create('partner_api_order_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('credential_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('external_order_id', 160);
            $table->string('status', 30)->default('processing');
            $table->timestamps();
            $table->unique(['partner_id', 'external_order_id'], 'partner_order_external_unique');
            $table->index(['tenant_id', 'branch_id', 'created_at']);
        });

        if (! Schema::hasTable('partner_api_nonces')) Schema::create('partner_api_nonces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credential_id');
            $table->char('nonce_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['credential_id', 'nonce_hash']);
            $table->index('expires_at');
        });

        if (! Schema::hasTable('partner_api_idempotency')) Schema::create('partner_api_idempotency', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credential_id');
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('processing');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['credential_id', 'key_hash']);
            $table->index(['status', 'created_at']);
        });

        if (! Schema::hasTable('partner_api_webhook_endpoints')) Schema::create('partner_api_webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('url', 2048);
            $table->text('secret_ciphertext');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();
            $table->index(['partner_id', 'is_active']);
        });

        if (! Schema::hasTable('partner_api_webhook_events')) Schema::create('partner_api_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('partner_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('event_type', 80);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['partner_id', 'created_at']);
        });

        if (! Schema::hasTable('partner_api_webhook_deliveries')) Schema::create('partner_api_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('endpoint_id');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'endpoint_id']);
            $table->index(['status', 'next_attempt_at']);
        });

        if (! Schema::hasTable('partner_api_request_logs')) Schema::create('partner_api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedBigInteger('credential_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->char('idempotency_hash', 64)->nullable();
            $table->string('external_order_id', 160)->nullable();
            $table->unsignedSmallInteger('response_status');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['credential_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_api_request_logs');
        Schema::dropIfExists('partner_api_webhook_deliveries');
        Schema::dropIfExists('partner_api_webhook_events');
        Schema::dropIfExists('partner_api_webhook_endpoints');
        Schema::dropIfExists('partner_api_idempotency');
        Schema::dropIfExists('partner_api_nonces');
        Schema::dropIfExists('partner_api_order_mappings');
        Schema::dropIfExists('partner_api_resource_mappings');
        Schema::dropIfExists('partner_api_credentials');
        Schema::dropIfExists('partner_api_integrations');
    }
};
