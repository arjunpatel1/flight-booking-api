<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_payment_gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->uuid('webhook_key')->unique();
            $table->boolean('enabled')->default(false);
            $table->boolean('test_mode')->default(true);
            $table->boolean('preferred')->default(false);
            $table->text('credentials')->nullable();
            $table->unsignedInteger('credential_version')->default(1);
            $table->string('settlement_identity')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 30)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
            $table->index(['tenant_id', 'enabled', 'preferred']);
        });

        Schema::create('tenant_payment_gateway_branches', function (Blueprint $table) {
            $table->foreignId('gateway_config_id')->constrained('tenant_payment_gateway_configs')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->primary(['gateway_config_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_payment_gateway_branches');
        Schema::dropIfExists('tenant_payment_gateway_configs');
    }
};
