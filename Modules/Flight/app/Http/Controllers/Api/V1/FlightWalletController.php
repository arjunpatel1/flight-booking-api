<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Exceptions\CreditLimitExceededException;
use Modules\Flight\Exceptions\DuplicateTransactionException;
use Modules\Flight\Exceptions\InsufficientBalanceException;
use Modules\Flight\Exceptions\WalletInactiveException;
use Modules\Flight\Models\FlightB2BWallet;
use Modules\Flight\Models\FlightWalletLedgerEntry;
use Modules\Flight\Models\FlightWalletTransaction;
use Modules\Flight\Services\Wallet\FlightWalletLedgerService;
use Modules\Support\ApiResponse;

class FlightWalletController extends Controller
{
    public function __construct(
        protected FlightWalletLedgerService $walletService
    ) {}

    protected function resolveTenantId(Request $request): ?int
    {
        return $request->user()?->tenant_id ?? auth()->user()?->tenant_id;
    }

    /**
     * List all flight wallets for current tenant.
     * GET /api/v1/flight/wallets
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $query = FlightB2BWallet::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        if ($request->filled('owner_type')) {
            $query->where('owner_type', strtoupper($request->string('owner_type')));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $wallets = $query->latest('id')->paginate($request->integer('per_page', 20));

        $wallets->getCollection()->transform(function (FlightB2BWallet $wallet) {
            $wallet->append('available_credit');
            $wallet->account_identifier = $wallet->account_identifier;
            return $wallet;
        });

        return ApiResponse::pagination($wallets);
    }

    /**
     * Get or create Tenant's primary wallet.
     * GET /api/v1/flight/wallets/tenant
     */
    public function tenantWallet(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request) ?? 1;
        $wallet = $this->walletService->getOrCreateTenantWallet($tenantId);
        $wallet->append('available_credit');

        return ApiResponse::success([
            'wallet'               => $wallet,
            'account_identifier'   => $wallet->account_identifier,
            'available_credit'     => $wallet->available_credit,
            'recent_transactions'  => FlightWalletTransaction::where('credit_wallet_id', $wallet->id)
                ->orWhere('debit_wallet_id', $wallet->id)
                ->latest('id')
                ->take(10)
                ->get(),
        ]);
    }

    /**
     * Show wallet details by ID.
     * GET /api/v1/flight/wallets/{id}
     */
    public function show(int $id): JsonResponse
    {
        $wallet = FlightB2BWallet::findOrFail($id);
        $wallet->append('available_credit');

        $recentTransactions = FlightWalletTransaction::where(function ($q) use ($id) {
            $q->where('debit_wallet_id', $id)
              ->orWhere('credit_wallet_id', $id);
        })->latest('id')->take(15)->get();

        return ApiResponse::success([
            'wallet'              => $wallet,
            'account_identifier'  => $wallet->account_identifier,
            'available_credit'    => $wallet->available_credit,
            'recent_transactions' => $recentTransactions,
        ]);
    }

    /**
     * Recharge wallet (external funding).
     * POST /api/v1/flight/wallets/{id}/recharge
     */
    public function recharge(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'reference'      => 'required|string|max:100',
            'actor'          => 'sometimes|string|max:100',
            'contra_account' => 'sometimes|nullable|string|max:100',
            'description'    => 'sometimes|nullable|string|max:255',
        ]);

        try {
            $transaction = $this->walletService->recharge(
                wallet: $id,
                amount: (float) $request->input('amount'),
                reference: $request->input('reference'),
                actor: $request->input('actor', 'Admin'),
                contraAccount: $request->input('contra_account', 'SYSTEM:PAYMENT_GATEWAY'),
                description: $request->input('description'),
                metadata: $request->input('metadata', [])
            );

            return ApiResponse::success([
                'transaction' => $transaction->fresh(['ledgerEntries']),
                'wallet'      => FlightB2BWallet::find($id)->append('available_credit'),
            ], 'Wallet recharged successfully.', 201);
        } catch (DuplicateTransactionException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (WalletInactiveException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Debit wallet.
     * POST /api/v1/flight/wallets/{id}/debit
     */
    public function debit(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'reference'      => 'required|string|max:100',
            'actor'          => 'sometimes|string|max:100',
            'contra_account' => 'sometimes|nullable|string|max:100',
            'description'    => 'sometimes|nullable|string|max:255',
        ]);

        try {
            $transaction = $this->walletService->debit(
                wallet: $id,
                amount: (float) $request->input('amount'),
                reference: $request->input('reference'),
                actor: $request->input('actor', 'Admin'),
                contraAccount: $request->input('contra_account', 'SYSTEM:WITHDRAWAL'),
                description: $request->input('description'),
                metadata: $request->input('metadata', [])
            );

            return ApiResponse::success([
                'transaction' => $transaction->fresh(['ledgerEntries']),
                'wallet'      => FlightB2BWallet::find($id)->append('available_credit'),
            ], 'Wallet debited successfully.');
        } catch (DuplicateTransactionException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (InsufficientBalanceException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (WalletInactiveException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Credit wallet.
     * POST /api/v1/flight/wallets/{id}/credit
     */
    public function credit(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'reference'      => 'required|string|max:100',
            'actor'          => 'sometimes|string|max:100',
            'contra_account' => 'sometimes|nullable|string|max:100',
            'description'    => 'sometimes|nullable|string|max:255',
        ]);

        try {
            $transaction = $this->walletService->credit(
                wallet: $id,
                amount: (float) $request->input('amount'),
                reference: $request->input('reference'),
                actor: $request->input('actor', 'Admin'),
                contraAccount: $request->input('contra_account', 'SYSTEM:DIRECT_CREDIT'),
                description: $request->input('description'),
                metadata: $request->input('metadata', [])
            );

            return ApiResponse::success([
                'transaction' => $transaction->fresh(['ledgerEntries']),
                'wallet'      => FlightB2BWallet::find($id)->append('available_credit'),
            ], 'Wallet credited successfully.');
        } catch (DuplicateTransactionException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (WalletInactiveException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Booking deduction.
     * POST /api/v1/flight/wallets/{id}/booking-deduction
     */
    public function bookingDeduction(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount'            => 'required|numeric|min:0.01',
            'booking_reference' => 'required|string|max:100',
            'actor'             => 'sometimes|string|max:100',
            'airline_escrow'    => 'sometimes|nullable|string|max:100',
        ]);

        try {
            $transaction = $this->walletService->bookingDeduction(
                wallet: $id,
                amount: (float) $request->input('amount'),
                bookingReference: $request->input('booking_reference'),
                actor: $request->input('actor', 'BookingEngine'),
                airlineEscrow: $request->input('airline_escrow'),
                metadata: $request->input('metadata', [])
            );

            return ApiResponse::success([
                'transaction' => $transaction->fresh(['ledgerEntries']),
                'wallet'      => FlightB2BWallet::find($id)->append('available_credit'),
            ], 'Booking deduction executed successfully.');
        } catch (DuplicateTransactionException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (InsufficientBalanceException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Refund credit.
     * POST /api/v1/flight/wallets/{id}/refund
     */
    public function refund(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount'            => 'required|numeric|min:0.01',
            'refund_reference'  => 'required|string|max:100',
            'booking_reference' => 'sometimes|nullable|string|max:100',
            'actor'             => 'sometimes|string|max:100',
        ]);

        try {
            $transaction = $this->walletService->refundCredit(
                wallet: $id,
                amount: (float) $request->input('amount'),
                refundReference: $request->input('refund_reference'),
                actor: $request->input('actor', 'RefundEngine'),
                bookingReference: $request->input('booking_reference'),
                metadata: $request->input('metadata', [])
            );

            return ApiResponse::success([
                'transaction' => $transaction->fresh(['ledgerEntries']),
                'wallet'      => FlightB2BWallet::find($id)->append('available_credit'),
            ], 'Refund credited successfully.');
        } catch (DuplicateTransactionException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Commission credit.
     * POST /api/v1/flight/wallets/{id}/commission
     */
    public function commission(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount'               => 'required|numeric|min:0.01',
            'commission_reference' => 'required|string|max:100',
            'actor'                => 'sometimes|string|max:100',
            'source_account'       => 'sometimes|nullable|string|max:100',
        ]);

        try {
            $transaction = $this->walletService->commissionCredit(
                wallet: $id,
                amount: (float) $request->input('amount'),
                commissionReference: $request->input('commission_reference'),
                actor: $request->input('actor', 'CommissionEngine'),
                sourceAccount: $request->input('source_account', 'TENANT:COMMISSION_EXPENSE'),
                metadata: $request->input('metadata', [])
            );

            return ApiResponse::success([
                'transaction' => $transaction->fresh(['ledgerEntries']),
                'wallet'      => FlightB2BWallet::find($id)->append('available_credit'),
            ], 'Commission credited successfully.');
        } catch (DuplicateTransactionException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Manual adjustment (positive or negative).
     * POST /api/v1/flight/wallets/{id}/adjustment
     */
    public function adjustment(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount'    => 'required|numeric',
            'reason'    => 'required|string|max:255',
            'reference' => 'required|string|max:100',
            'actor'     => 'sometimes|string|max:100',
        ]);

        try {
            $transaction = $this->walletService->manualAdjustment(
                wallet: $id,
                amount: (float) $request->input('amount'),
                reason: $request->input('reason'),
                actor: $request->input('actor', 'Tenant Admin'),
                reference: $request->input('reference'),
                metadata: $request->input('metadata', [])
            );

            return ApiResponse::success([
                'transaction' => $transaction->fresh(['ledgerEntries']),
                'wallet'      => FlightB2BWallet::find($id)->append('available_credit'),
            ], 'Manual adjustment applied successfully.');
        } catch (DuplicateTransactionException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (InsufficientBalanceException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Inter-wallet transfer.
     * POST /api/v1/flight/wallets/transfer
     */
    public function transfer(Request $request): JsonResponse
    {
        $request->validate([
            'from_wallet_id' => 'required|integer|exists:flight_b2b_wallets,id',
            'to_wallet_id'   => 'required|integer|exists:flight_b2b_wallets,id',
            'amount'         => 'required|numeric|min:0.01',
            'reference'      => 'required|string|max:100',
            'actor'          => 'sometimes|string|max:100',
            'description'    => 'sometimes|nullable|string|max:255',
        ]);

        try {
            $transaction = $this->walletService->transfer(
                fromWallet: $request->integer('from_wallet_id'),
                toWallet: $request->integer('to_wallet_id'),
                amount: (float) $request->input('amount'),
                reference: $request->input('reference'),
                actor: $request->input('actor', 'User'),
                description: $request->input('description')
            );

            return ApiResponse::success([
                'transaction' => $transaction->fresh(['ledgerEntries']),
                'from_wallet' => FlightB2BWallet::find($request->integer('from_wallet_id'))->append('available_credit'),
                'to_wallet'   => FlightB2BWallet::find($request->integer('to_wallet_id'))->append('available_credit'),
            ], 'Transfer processed successfully.');
        } catch (DuplicateTransactionException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        } catch (InsufficientBalanceException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Adjust credit limit.
     * POST /api/v1/flight/wallets/{id}/credit-limit
     */
    public function adjustCreditLimit(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'credit_limit' => 'required|numeric|min:0',
            'actor'        => 'sometimes|string|max:100',
            'reference'    => 'sometimes|string|max:100',
        ]);

        try {
            $wallet = $this->walletService->adjustCreditLimit(
                wallet: $id,
                newLimit: (float) $request->input('credit_limit'),
                actor: $request->input('actor', 'Admin'),
                reference: $request->input('reference', 'CRDLIM-' . time())
            );

            return ApiResponse::success([
                'wallet'           => $wallet->append('available_credit'),
                'available_credit' => $wallet->available_credit,
            ], 'Credit limit adjusted successfully.');
        } catch (CreditLimitExceededException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Financial transaction history.
     * GET /api/v1/flight/wallets/{walletId?}/transactions
     */
    public function transactions(Request $request, ?int $walletId = null): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);

        $query = FlightWalletTransaction::with(['ledgerEntries'])
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId));

        if ($walletId) {
            $query->where(function ($q) use ($walletId) {
                $q->where('debit_wallet_id', $walletId)
                  ->orWhere('credit_wallet_id', $walletId);
            });
        }

        if ($request->filled('type')) {
            $query->where('type', strtoupper($request->string('type')));
        }

        if ($request->filled('reference')) {
            $query->where('reference', 'LIKE', '%' . $request->string('reference') . '%');
        }

        $transactions = $query->latest('id')->paginate($request->integer('per_page', 25));

        return ApiResponse::pagination($transactions);
    }

    /**
     * Double-entry ledger entries for a wallet.
     * GET /api/v1/flight/wallets/{id}/ledger
     */
    public function ledgerEntries(Request $request, int $id): JsonResponse
    {
        $entries = FlightWalletLedgerEntry::with('transaction')
            ->where('wallet_id', $id)
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::pagination($entries);
    }

    /**
     * Verify double-entry consistency across all ledger entries.
     * GET /api/v1/flight/wallets/ledger/consistency
     */
    public function verifyLedger(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $audit = $this->walletService->verifyLedgerConsistency($tenantId);

        return ApiResponse::success($audit, 'Ledger consistency audit completed.');
    }

    /**
     * Render frontend wallet workspace HTML.
     * GET /api/v1/flight/wallets/workspace
     */
    public function workspace(Request $request)
    {
        $tenantId = $this->resolveTenantId($request) ?? 1;
        $tenantWallet = $this->walletService->getOrCreateTenantWallet($tenantId);
        $tenantWallet->append('available_credit');

        $wallets = FlightB2BWallet::where('tenant_id', $tenantId)->latest('id')->get();
        $wallets->transform(fn ($w) => $w->append('available_credit'));

        $recentTransactions = FlightWalletTransaction::where('tenant_id', $tenantId)
            ->latest('id')
            ->take(20)
            ->get();

        $consistency = $this->walletService->verifyLedgerConsistency($tenantId);

        if ($request->wantsJson()) {
            return ApiResponse::success([
                'tenant_wallet'       => $tenantWallet,
                'wallets'             => $wallets,
                'recent_transactions' => $recentTransactions,
                'ledger_consistency'  => $consistency,
            ]);
        }

        return view('flight::wallet.workspace', [
            'tenantWallet'       => $tenantWallet,
            'wallets'            => $wallets,
            'recentTransactions' => $recentTransactions,
            'consistency'        => $consistency,
        ]);
    }
}
