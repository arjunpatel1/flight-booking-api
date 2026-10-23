<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 8: B2B Distributor + Agent Network
     * Implements the hierarchy: Tenant -> Distributor -> Agent.
     */
    public function up(): void
    {
        if (! Schema::hasTable('flight_distributors')) {
            Schema::create('flight_distributors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('name');
                $table->string('code', 50)->index();
                $table->string('company_name')->nullable();
                $table->string('email');
                $table->string('phone', 30)->nullable();
                $table->string('status', 20)->default('ACTIVE')->index(); // ACTIVE, SUSPENDED, INACTIVE
                $table->decimal('credit_limit', 12, 2)->default(0.00);
                $table->decimal('credit_balance', 12, 2)->default(0.00);
                $table->decimal('commission_rate', 8, 4)->default(0.0000);
                $table->boolean('allow_sub_agents')->default(true);
                $table->integer('max_agents')->default(50);
                $table->text('notes')->nullable();
                $table->dateTime('suspended_at')->nullable();
                $table->string('suspended_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code'], 'uniq_fdist_tenant_code');
                $table->index(['tenant_id', 'status'], 'idx_fdist_tenant_status');
            });
        }

        if (! Schema::hasTable('flight_agents')) {
            Schema::create('flight_agents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('flight_distributor_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('name');
                $table->string('code', 50)->index();
                $table->string('agency_name')->nullable();
                $table->string('iata_number', 30)->nullable();
                $table->string('email');
                $table->string('phone', 30)->nullable();
                $table->string('status', 20)->default('ACTIVE')->index(); // ACTIVE, SUSPENDED, INACTIVE
                $table->decimal('credit_limit', 12, 2)->default(0.00);
                $table->decimal('credit_balance', 12, 2)->default(0.00);
                $table->decimal('commission_rate', 8, 4)->default(0.0000);
                $table->text('notes')->nullable();
                $table->dateTime('suspended_at')->nullable();
                $table->string('suspended_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code'], 'uniq_fagent_tenant_code');
                $table->index(['tenant_id', 'flight_distributor_id', 'status'], 'idx_fagent_dist_status');
            });
        }

        if (! Schema::hasTable('flight_b2b_wallets')) {
            Schema::create('flight_b2b_wallets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('owner_type', 20)->index(); // DISTRIBUTOR, AGENT
                $table->unsignedBigInteger('owner_id')->index();
                $table->string('currency', 3)->default('INR');
                $table->decimal('balance', 12, 2)->default(0.00);
                $table->decimal('credit_limit', 12, 2)->default(0.00);
                $table->decimal('credit_used', 12, 2)->default(0.00);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'owner_type', 'owner_id'], 'uniq_fb2b_wallet_owner');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_b2b_wallets');
        Schema::dropIfExists('flight_agents');
        Schema::dropIfExists('flight_distributors');
    }
};
