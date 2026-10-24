<?php

namespace Modules\Flight\Services\Supplier;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Flight\Services\Supplier\Exceptions\SupplierException;
use Throwable;

/**
 * Phase 5: Supplier Transaction Guard
 *
 * Enforces zero-corruption guarantees:
 * If an external supplier call fails, times out, or returns a fatal status,
 * any active database transaction is immediately rolled back, preventing corruption of:
 *  - Inventory ledger (flight_inventory_transactions, flight_inventory_balances)
 *  - Wallet ledger / double-entry accounting records
 *  - Booking history
 *  - Financial records
 */
class SupplierTransactionGuard
{
    /**
     * Executes an action within an atomic database transaction.
     * Guarantees rollback if the supplier or surrounding logic fails.
     *
     * @throws SupplierException
     * @throws Throwable
     */
    public static function run(callable $supplierOperation, ?callable $onRollback = null): mixed
    {
        DB::beginTransaction();

        try {
            $result = $supplierOperation();

            DB::commit();

            return $result;
        } catch (Throwable $e) {
            DB::rollBack();

            Log::error("SupplierTransactionGuard: Transaction rolled back to protect ledgers from corruption. Error: {$e->getMessage()}", [
                'exception' => get_class($e),
            ]);

            if ($onRollback) {
                try {
                    $onRollback($e);
                } catch (Throwable $rollbackError) {
                    Log::error("SupplierTransactionGuard onRollback failed: {$rollbackError->getMessage()}");
                }
            }

            throw $e;
        }
    }
}
