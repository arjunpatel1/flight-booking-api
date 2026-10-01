<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_app_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('package_id')->unique();
            $table->string('display_name');
            $table->string('platform', 16);
            $table->string('status', 16)->default('inactive');
            $table->unsignedBigInteger('branding_revision')->default(1);
            $table->string('signing_certificate_fingerprint', 128)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform'], 'customer_app_tenant_platform_unique');
            $table->index(['tenant_id', 'status'], 'customer_app_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_app_registrations');
    }
};
