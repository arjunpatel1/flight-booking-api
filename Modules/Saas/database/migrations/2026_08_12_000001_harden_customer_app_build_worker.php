<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_app_builds', function (Blueprint $table): void {
            $table->string('worker_id', 96)->nullable()->after('status');
            $table->unsignedTinyInteger('attempt')->default(0)->after('worker_id');
            $table->timestamp('claimed_at')->nullable()->after('queued_at');
            $table->timestamp('lease_expires_at')->nullable()->after('claimed_at');
            $table->timestamp('last_heartbeat_at')->nullable()->after('lease_expires_at');
            $table->json('build_metadata')->nullable()->after('config_snapshot');
            $table->index(['status', 'lease_expires_at', 'queued_at'], 'customer_app_build_worker_claim_idx');
        });
        Schema::table('customer_app_build_artifacts', function (Blueprint $table): void {
            // Default keeps this additive for installations that already have
            // Phase 2 artifacts; new worker uploads always set it explicitly.
            $table->string('mime_type', 128)->default('application/octet-stream')->after('size_bytes');
            $table->timestamp('verified_at')->nullable()->after('mime_type');
            $table->timestamp('retention_expires_at')->nullable()->after('verified_at');
            $table->json('verification_metadata')->nullable()->after('retention_expires_at');
            $table->index(['retention_expires_at', 'revoked_at'], 'customer_app_artifact_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_app_build_artifacts', function (Blueprint $table): void {
            $table->dropIndex('customer_app_artifact_retention_idx');
            $table->dropColumn(['mime_type', 'verified_at', 'retention_expires_at', 'verification_metadata']);
        });
        Schema::table('customer_app_builds', function (Blueprint $table): void {
            $table->dropIndex('customer_app_build_worker_claim_idx');
            $table->dropColumn(['worker_id', 'attempt', 'claimed_at', 'lease_expires_at', 'last_heartbeat_at', 'build_metadata']);
        });
    }
};
