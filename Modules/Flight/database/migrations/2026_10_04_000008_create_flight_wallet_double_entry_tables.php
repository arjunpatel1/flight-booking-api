<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Phase 9: Wallet + Double-Entry Ledger + Credit.
     */
    public function up(): void
    {
        // 1. Add account_number to flight_b2b_wallets if missing
        if (Schema::hasTable('flight_b2b_wallets')) {
            Schema::table('flight_b2b_wallets', function (Blueprint $table) {
                if (! Schema::hasColumn('flight_b2b_wallets', 'account_number')) {
                    $table->string('account_number', 64)->nullable()->after('owner_id')->index();
                }
            });
        }

        // 2. Flight Wallet Transactions (Financial Journal Records)
        if (! Schema::hasTable('flight_wallet_transactions')) {
            Schema::create('flight_wallet_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('transaction_id', 64)->unique('uniq_fwt_txnid');
                $table->string('reference', 100)->index('idx_fwt_ref');
                $table->string('type', 32)->index('idx_fwt_type');
                
                $table->string('debit_account', 100);
                $table->string('credit_account', 100);
                $table->unsignedBigInteger('debit_wallet_id')->nullable()->index('idx_fwt_deb_wal');
                $table->unsignedBigInteger('credit_wallet_id')->nullable()->index('idx_fwt_crd_wal');
                
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3)->default('INR');
                
                // Primary wallet opening and closing balance
                $table->decimal('opening_balance', 12, 2)->default(0.00);
                $table->decimal('closing_balance', 12, 2)->default(0.00);
                
                // Specific debit/credit account balances
                $table->decimal('debit_opening_balance', 12, 2)->nullable();
                $table->decimal('debit_closing_balance', 12, 2)->nullable();
                $table->decimal('credit_opening_balance', 12, 2)->nullable();
                $table->decimal('credit_closing_balance', 12, 2)->nullable();
                
                $table->string('actor', 100);
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'created_at'], 'idx_fwt_tenant_created');
                $table->index(['tenant_id', 'type'], 'idx_fwt_tenant_type');
            });
        }

        // 3. Flight Wallet Ledger Entries (Strict Double-Entry Balanced Lines)
        if (! Schema::hasTable('flight_wallet_ledger_entries')) {
            Schema::create('flight_wallet_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('flight_wallet_transaction_id')->index('idx_fwle_txnid');
                $table->unsignedBigInteger('wallet_id')->nullable()->index('idx_fwle_walid');
                
                $table->string('account_identifier', 100);
                $table->string('entry_type', 10); // DEBIT or CREDIT
                $table->decimal('amount', 12, 2);
                $table->decimal('opening_balance', 12, 2)->default(0.00);
                $table->decimal('closing_balance', 12, 2)->default(0.00);
                $table->timestamps();

                $table->index(['tenant_id', 'account_identifier'], 'idx_fwle_account');
                $table->index(['tenant_id', 'entry_type'], 'idx_fwle_tenant_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_wallet_ledger_entries');
        Schema::dropIfExists('flight_wallet_transactions');

        if (Schema::hasTable('flight_b2b_wallets')) {
            Schema::table('flight_b2b_wallets', function (Blueprint $table) {
                if (Schema::hasColumn('flight_b2b_wallets', 'account_number')) {
                    $table->dropColumn('account_number');
                }
            });
        }
    }
};
