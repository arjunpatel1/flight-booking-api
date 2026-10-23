<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Flight Inventory Allocation & Transfer
 *
 * Creates four tables that implement the multi-tier inventory distribution layer:
 *
 *   flight_inventory_balances      — current seat count per tier/holder
 *   flight_inventory_allocations   — allocation records in the hierarchy
 *   flight_inventory_transactions  — full audit ledger (every seat movement)
 *   flight_inventory_reservations  — temporary holds (booking pipeline)
 *
 * Hierarchy:
 *   SuperAdmin → Central Pool → Tenant → Distributor → Agent → B2C
 *
 * IMPORTANT: Never touches Restaurant tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Inventory Balances ─────────────────────────────────────────────
        // One row per (flight_inventory, holder_type, holder_id, cabin_class).
        // This is the single source of truth for how many seats a tier holds.
        if (! Schema::hasTable('flight_inventory_balances')) {
            Schema::create('flight_inventory_balances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable()->comment('null = central pool');
                $table->unsignedBigInteger('flight_inventory_id');

                // Holder identity (polymorphic-style flat columns for query speed)
                $table->enum('holder_type', [
                    'central',      // Super-admin central pool
                    'tenant',       // Tenant pool
                    'distributor',  // Distributor channel
                    'agent',        // Agent channel
                    'b2c',          // B2C channel
                ])->default('tenant');
                $table->unsignedBigInteger('holder_id')->nullable()
                    ->comment('NULL for central/tenant; user/channel id otherwise');

                $table->enum('cabin_class', ['ECONOMY', 'PREMIUM_ECONOMY', 'BUSINESS', 'FIRST'])
                    ->default('ECONOMY');

                // Seat counters (always non-negative; enforced in service layer)
                $table->unsignedInteger('total_allocated')->default(0)
                    ->comment('Seats allocated to this holder from parent');
                $table->unsignedInteger('available')->default(0)
                    ->comment('Seats available for sub-allocation or booking');
                $table->unsignedInteger('reserved')->default(0)
                    ->comment('Temporarily held for active booking sessions');
                $table->unsignedInteger('booked')->default(0)
                    ->comment('Confirmed bookings');
                $table->unsignedInteger('frozen')->default(0)
                    ->comment('Admin-frozen, cannot be booked or transferred');

                $table->enum('allocation_mode', [
                    'fixed',        // Hard allocation: holder owns a fixed block
                    'shared_pool',  // Draws from parent on demand
                    'hybrid',       // Fixed floor + shared top-up
                ])->default('fixed');

                // Hybrid mode: minimum guaranteed seats
                $table->unsignedInteger('floor_seats')->default(0);

                $table->boolean('is_active')->default(true);
                $table->timestamp('expires_at')->nullable()
                    ->comment('When this balance auto-expires and returns to parent');
                $table->timestamps();

                $table->foreign('flight_inventory_id')
                    ->references('id')->on('flight_inventories')->onDelete('cascade');

                $table->unique(
                    ['flight_inventory_id', 'holder_type', 'holder_id', 'cabin_class'],
                    'uk_balance_holder'
                );
                $table->index(['tenant_id', 'flight_inventory_id', 'holder_type'], 'idx_bal_tenant_inv');
                $table->index(['expires_at'], 'idx_bal_expiry');
            });
        }

        // ── 2. Inventory Allocations ──────────────────────────────────────────
        // Each row represents one allocation event in the hierarchy.
        // parent_allocation_id links child → parent so over-allocation is prevented.
        if (! Schema::hasTable('flight_inventory_allocations')) {
            Schema::create('flight_inventory_allocations', function (Blueprint $table) {
                $table->id();
                $table->string('allocation_ref', 36)->unique()
                    ->comment('UUID reference for external communication');

                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('flight_inventory_id');
                $table->enum('cabin_class', ['ECONOMY', 'PREMIUM_ECONOMY', 'BUSINESS', 'FIRST'])
                    ->default('ECONOMY');

                // Source (who is giving seats)
                $table->enum('from_holder_type', ['central', 'tenant', 'distributor', 'agent']);
                $table->unsignedBigInteger('from_holder_id')->nullable();

                // Destination (who is receiving seats)
                $table->enum('to_holder_type', ['tenant', 'distributor', 'agent', 'b2c']);
                $table->unsignedBigInteger('to_holder_id')->nullable();

                // Hierarchy link — prevents child from exceeding parent's balance
                $table->unsignedBigInteger('parent_allocation_id')->nullable()
                    ->comment('The allocation that funded this one');

                $table->unsignedInteger('seats_requested');
                $table->unsignedInteger('seats_allocated')->default(0)
                    ->comment('May differ from requested if partial approval');

                $table->enum('allocation_mode', ['fixed', 'shared_pool', 'hybrid'])->default('fixed');
                $table->unsignedInteger('floor_seats')->default(0)
                    ->comment('Hybrid: guaranteed minimum');

                $table->enum('status', [
                    'REQUESTED',
                    'APPROVED',
                    'PROCESSING',
                    'COMPLETED',
                    'REJECTED',
                    'CANCELLED',
                    'REVERSED',
                ])->default('REQUESTED');

                $table->unsignedBigInteger('requested_by')->nullable()
                    ->comment('auth user id');
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->unsignedBigInteger('processed_by')->nullable();

                $table->timestamp('requested_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('expires_at')->nullable()
                    ->comment('Auto-return to parent when expired');

                $table->text('notes')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->json('meta')->nullable()->comment('Arbitrary audit/context data');

                $table->timestamps();
                $table->softDeletes();

                $table->foreign('flight_inventory_id')
                    ->references('id')->on('flight_inventories')->onDelete('cascade');
                $table->foreign('parent_allocation_id')
                    ->references('id')->on('flight_inventory_allocations')->onDelete('set null');

                $table->index(['tenant_id', 'flight_inventory_id', 'status'], 'idx_alloc_tenant_inv_status');
                $table->index(['from_holder_type', 'from_holder_id'], 'idx_alloc_from');
                $table->index(['to_holder_type', 'to_holder_id'], 'idx_alloc_to');
                $table->index(['status', 'expires_at'], 'idx_alloc_status_expiry');
                $table->index(['parent_allocation_id'], 'idx_alloc_parent');
            });
        }

        // ── 3. Inventory Transactions ──────────────────────────────────────────
        // Immutable append-only ledger.  Every seat movement creates a row here.
        if (! Schema::hasTable('flight_inventory_transactions')) {
            Schema::create('flight_inventory_transactions', function (Blueprint $table) {
                $table->id();
                $table->string('transaction_ref', 36)->unique()
                    ->comment('UUID for idempotency checks');

                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('flight_inventory_id');
                $table->enum('cabin_class', ['ECONOMY', 'PREMIUM_ECONOMY', 'BUSINESS', 'FIRST'])
                    ->default('ECONOMY');

                $table->unsignedBigInteger('allocation_id')->nullable()
                    ->comment('Related allocation record, if any');

                $table->enum('transaction_type', [
                    'INITIAL_ALLOCATION',
                    'TENANT_TRANSFER',
                    'CHANNEL_ALLOCATION',
                    'CHANNEL_RETURN',
                    'BOOKING',
                    'RELEASE',
                    'CANCELLATION',
                    'REFUND',
                    'EXPIRY',
                    'FREEZE',
                    'UNFREEZE',
                    'ADJUSTMENT',
                    'RECONCILIATION',
                ]);

                // Who the movement came from
                $table->enum('from_holder_type', [
                    'central', 'tenant', 'distributor', 'agent', 'b2c', 'system',
                ])->default('system');
                $table->unsignedBigInteger('from_holder_id')->nullable();

                // Who the movement went to
                $table->enum('to_holder_type', [
                    'central', 'tenant', 'distributor', 'agent', 'b2c', 'system',
                ])->default('system');
                $table->unsignedBigInteger('to_holder_id')->nullable();

                $table->integer('seats_delta')
                    ->comment('Positive = added, negative = removed');

                // Snapshot for audit
                $table->unsignedInteger('from_balance_before')->default(0);
                $table->unsignedInteger('from_balance_after')->default(0);
                $table->unsignedInteger('to_balance_before')->default(0);
                $table->unsignedInteger('to_balance_after')->default(0);

                $table->unsignedBigInteger('actor_id')->nullable()
                    ->comment('User who triggered this transaction');
                $table->string('actor_ip', 45)->nullable();
                $table->string('reason', 500)->nullable();
                $table->json('meta')->nullable();

                // Immutable — no softDeletes; no updated_at
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('flight_inventory_id')
                    ->references('id')->on('flight_inventories')->onDelete('cascade');
                $table->foreign('allocation_id')
                    ->references('id')->on('flight_inventory_allocations')->onDelete('set null');

                $table->index(
                    ['tenant_id', 'flight_inventory_id', 'transaction_type'],
                    'idx_txn_tenant_inv_type'
                );
                $table->index(['from_holder_type', 'from_holder_id'], 'idx_txn_from');
                $table->index(['to_holder_type', 'to_holder_id'], 'idx_txn_to');
                $table->index(['allocation_id'], 'idx_txn_allocation');
                $table->index(['created_at'], 'idx_txn_created');
            });
        }

        // ── 4. Inventory Reservations ─────────────────────────────────────────
        // Short-lived holds during the booking flow.
        // Released on booking confirmation, expiry, or explicit release.
        if (! Schema::hasTable('flight_inventory_reservations')) {
            Schema::create('flight_inventory_reservations', function (Blueprint $table) {
                $table->id();
                $table->string('reservation_ref', 36)->unique();

                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('flight_inventory_id');
                $table->enum('cabin_class', ['ECONOMY', 'PREMIUM_ECONOMY', 'BUSINESS', 'FIRST'])
                    ->default('ECONOMY');

                $table->enum('holder_type', ['tenant', 'distributor', 'agent', 'b2c'])
                    ->default('tenant');
                $table->unsignedBigInteger('holder_id')->nullable();

                $table->unsignedInteger('seats_reserved')->default(1);

                $table->enum('status', [
                    'ACTIVE',       // Holding seats
                    'CONFIRMED',    // Booking confirmed, seats moved to booked
                    'RELEASED',     // Manually released
                    'EXPIRED',      // TTL exceeded
                    'CANCELLED',    // Booking cancelled
                ])->default('ACTIVE');

                // Session context
                $table->string('session_token', 64)->nullable()->index();
                $table->string('booking_reference', 64)->nullable()->index()
                    ->comment('Set on confirmation');

                $table->unsignedBigInteger('reserved_by')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('released_at')->nullable();

                $table->timestamps();

                $table->foreign('tenant_id')
                    ->references('id')->on('tenants')->onDelete('cascade');
                $table->foreign('flight_inventory_id')
                    ->references('id')->on('flight_inventories')->onDelete('cascade');

                $table->index(
                    ['flight_inventory_id', 'status', 'expires_at'],
                    'idx_res_inv_status_expiry'
                );
                $table->index(['tenant_id', 'status'], 'idx_res_tenant_status');
                $table->index(['expires_at', 'status'], 'idx_res_expiry_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_inventory_reservations');
        Schema::dropIfExists('flight_inventory_transactions');
        Schema::dropIfExists('flight_inventory_allocations');
        Schema::dropIfExists('flight_inventory_balances');
    }
};
