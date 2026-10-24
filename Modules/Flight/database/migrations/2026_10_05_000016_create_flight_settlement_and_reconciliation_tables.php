<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 18: Commission Settlement + 4-Way Reconciliation + Immutable Audit Reports
     */
    public function up(): void
    {
        // 1. Settlement Batches
        if (! Schema::hasTable('flight_settlement_batches')) {
            Schema::create('flight_settlement_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('batch_reference', 64)->unique();
                $table->timestamp('period_start')->index();
                $table->timestamp('period_end')->index();
                $table->string('supplier_code', 50)->nullable()->index();
                $table->unsignedBigInteger('distributor_id')->nullable()->index();
                $table->unsignedBigInteger('agent_id')->nullable()->index();

                // Settlement status lifecycle
                $table->string('status', 30)->default('PENDING')->index();
                $table->string('reconciliation_status', 30)->default('PENDING')->index();

                // Aggregated financials
                $table->unsignedInteger('total_bookings_count')->default(0);
                $table->decimal('total_booking_amount', 14, 2)->default(0.00);
                $table->decimal('total_supplier_payable', 14, 2)->default(0.00);
                $table->decimal('total_platform_revenue', 14, 2)->default(0.00);
                $table->decimal('total_tenant_revenue', 14, 2)->default(0.00);
                $table->decimal('total_distributor_commission', 14, 2)->default(0.00);
                $table->decimal('total_agent_commission', 14, 2)->default(0.00);
                $table->decimal('total_taxes', 14, 2)->default(0.00);
                $table->decimal('total_fees', 14, 2)->default(0.00);
                $table->string('currency', 3)->default('INR');

                // Mismatch and audit tracking
                $table->unsignedInteger('mismatches_count')->default(0);
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->text('failure_reason')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('processed_by')->nullable()->index();
                $table->json('metadata')->nullable();

                $table->timestamps();
            });
        }

        // 2. Settlement Items (per booking line item in batch)
        if (! Schema::hasTable('flight_settlement_items')) {
            Schema::create('flight_settlement_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('flight_settlement_batch_id')->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('flight_booking_id')->index();
                $table->string('booking_reference', 64)->index();
                $table->string('supplier_code', 50)->nullable()->index();
                $table->unsignedBigInteger('distributor_id')->nullable()->index();
                $table->unsignedBigInteger('agent_id')->nullable()->index();

                // Line item amounts
                $table->decimal('booking_amount', 12, 2)->default(0.00);
                $table->decimal('supplier_payable', 12, 2)->default(0.00);
                $table->decimal('platform_revenue', 12, 2)->default(0.00);
                $table->decimal('tenant_revenue', 12, 2)->default(0.00);
                $table->decimal('distributor_commission', 12, 2)->default(0.00);
                $table->decimal('agent_commission', 12, 2)->default(0.00);
                $table->decimal('taxes', 12, 2)->default(0.00);
                $table->decimal('fees', 12, 2)->default(0.00);
                $table->string('currency', 3)->default('INR');

                // Status & Reconciliation
                $table->string('status', 30)->default('PENDING')->index();
                $table->string('reconciliation_status', 30)->default('PENDING')->index();
                $table->boolean('has_mismatch')->default(false)->index();
                $table->json('reconciliation_details')->nullable();
                $table->json('calculation_breakdown')->nullable();

                $table->timestamps();

                $table->foreign('flight_settlement_batch_id', 'fk_fs_items_batch')
                    ->references('id')
                    ->on('flight_settlement_batches')
                    ->cascadeOnDelete();
            });
        }

        // 3. Settlement Mismatches (discrepancy detection records)
        if (! Schema::hasTable('flight_settlement_mismatches')) {
            Schema::create('flight_settlement_mismatches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('flight_settlement_batch_id')->index();
                $table->unsignedBigInteger('flight_settlement_item_id')->nullable()->index();
                $table->unsignedBigInteger('flight_booking_id')->index();
                $table->string('booking_reference', 64)->index();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();

                $table->string('mismatch_type', 50)->index();
                $table->string('source_entity', 50)->default('BOOKING');
                $table->decimal('expected_amount', 12, 2)->default(0.00);
                $table->decimal('actual_amount', 12, 2)->default(0.00);
                $table->decimal('discrepancy_amount', 12, 2)->default(0.00);
                $table->string('severity', 20)->default('MEDIUM')->index();
                $table->string('status', 30)->default('UNRESOLVED')->index();

                $table->json('details')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable()->index();
                $table->text('resolution_notes')->nullable();

                $table->timestamps();

                $table->foreign('flight_settlement_batch_id', 'fk_fs_mismatches_batch')
                    ->references('id')
                    ->on('flight_settlement_batches')
                    ->cascadeOnDelete();
            });
        }

        // 4. Immutable Audit Reports
        if (! Schema::hasTable('flight_settlement_audit_reports')) {
            Schema::create('flight_settlement_audit_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedBigInteger('flight_settlement_batch_id')->index();
                $table->string('report_reference', 64)->unique();
                $table->string('title', 255);
                $table->string('report_type', 50)->default('SETTLEMENT_RECONCILIATION');
                $table->string('checksum_sha256', 64)->index();
                $table->boolean('is_immutable')->default(true);

                $table->json('summary');
                $table->json('financial_breakdown');
                $table->json('reconciliation_findings');
                $table->json('mismatch_records')->nullable();

                $table->unsignedBigInteger('generated_by')->nullable()->index();
                $table->timestamp('generated_at');
                $table->timestamps();

                $table->foreign('flight_settlement_batch_id', 'fk_fs_reports_batch')
                    ->references('id')
                    ->on('flight_settlement_batches')
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_settlement_audit_reports');
        Schema::dropIfExists('flight_settlement_mismatches');
        Schema::dropIfExists('flight_settlement_items');
        Schema::dropIfExists('flight_settlement_batches');
    }
};
