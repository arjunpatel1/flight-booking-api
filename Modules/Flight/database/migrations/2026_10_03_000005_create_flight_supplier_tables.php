<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Phase 5: Supplier Integration Abstraction.
     */
    public function up(): void
    {
        // 1. flight_suppliers
        Schema::create('flight_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('code', 50)->index();
            $table->string('name', 150);
            $table->string('type', 50)->default('gds'); // gds, ndc, airline_api, consolidator, manual, contracted
            $table->string('adapter_class', 255);
            $table->string('environment', 30)->default('sandbox'); // sandbox, production, test
            $table->string('health_status', 30)->default('ACTIVE'); // ACTIVE, INACTIVE, DEGRADED, FAILED
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->integer('timeout_seconds')->default(15);
            $table->timestamp('last_health_check_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active'], 'idx_supp_tenant_active');
            $table->index(['type', 'health_status'], 'idx_supp_type_health');
        });

        // 2. flight_supplier_configurations
        Schema::create('flight_supplier_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_supplier_id')->constrained('flight_suppliers')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('endpoint_url', 500);
            $table->string('backup_endpoint_url', 500)->nullable();
            $table->string('currency', 3)->default('INR');
            $table->json('supported_cabins')->nullable();
            $table->json('supported_operations')->nullable();
            $table->integer('timeout_ms')->default(10000);
            $table->integer('retry_count')->default(2);
            $table->integer('rate_limit_per_minute')->nullable();
            $table->json('extra_settings')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index(['flight_supplier_id', 'is_enabled'], 'idx_supp_cfg_supp_enabled');
        });

        // 3. flight_supplier_credentials
        Schema::create('flight_supplier_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_supplier_id')->constrained('flight_suppliers')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('client_id', 255)->nullable();
            $table->text('client_secret')->nullable(); // encrypted
            $table->text('api_key')->nullable();       // encrypted
            $table->text('auth_token')->nullable();    // encrypted
            $table->string('office_id', 100)->nullable();
            $table->string('corporate_code', 100)->nullable();
            $table->string('certificate_path', 500)->nullable();
            $table->text('additional_credentials')->nullable(); // encrypted json
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();

            $table->index('flight_supplier_id', 'idx_supp_cred_supplier');
        });

        // 4. flight_supplier_mappings
        Schema::create('flight_supplier_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_supplier_id')->constrained('flight_suppliers')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('mapping_type', 50); // airline, flight, fare, inventory, reference, pnr, ticket
            $table->unsignedBigInteger('internal_id')->nullable();
            $table->string('internal_code', 100)->nullable();
            $table->string('supplier_code', 100)->nullable();
            $table->string('supplier_reference', 255)->nullable();
            $table->string('supplier_pnr', 50)->nullable();
            $table->string('supplier_ticket_number', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['flight_supplier_id', 'mapping_type'], 'idx_supp_map_supp_type');
            $table->index('supplier_pnr', 'idx_supp_map_pnr');
            $table->index('supplier_ticket_number', 'idx_supp_map_ticket');
            $table->index('supplier_reference', 'idx_supp_map_ref');
        });

        // 5. flight_supplier_health_logs
        Schema::create('flight_supplier_health_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_supplier_id')->constrained('flight_suppliers')->cascadeOnDelete();
            $table->string('status', 30); // ACTIVE, INACTIVE, DEGRADED, FAILED
            $table->integer('latency_ms')->nullable();
            $table->string('endpoint', 500)->nullable();
            $table->boolean('is_healthy');
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index(['flight_supplier_id', 'checked_at'], 'idx_supp_hlth_supp_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_supplier_health_logs');
        Schema::dropIfExists('flight_supplier_mappings');
        Schema::dropIfExists('flight_supplier_credentials');
        Schema::dropIfExists('flight_supplier_configurations');
        Schema::dropIfExists('flight_suppliers');
    }
};
