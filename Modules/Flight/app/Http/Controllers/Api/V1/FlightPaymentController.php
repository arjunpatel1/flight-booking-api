<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Exceptions\InsufficientBalanceException;
use Modules\Flight\Services\Payment\FlightPaymentService;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;

class FlightPaymentController extends Controller
{
    public function __construct(
        protected FlightPaymentService $paymentService
    ) {
    }

    /**
     * Initiate an online payment gateway session for a booking.
     * POST /api/v1/flight/bookings/{identifier}/payment
     */
    public function initiate(Request $request, string $identifier): JsonResponse
    {
        try {
            $tenant = $this->resolveTenant($request);
            $user = $request->user();

            $result = $this->paymentService->initiatePayment(
                $identifier,
                $request->all(),
                $tenant,
                $user
            );

            return ApiResponse::created($result);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * Verify payment signature from client gateway callback.
     * POST /api/v1/flight/payments/{reference}/verify
     */
    public function verify(Request $request, string $reference): JsonResponse
    {
        try {
            $tenant = $this->resolveTenant($request);

            $payment = $this->paymentService->verifyPayment(
                $reference,
                $request->all(),
                $tenant
            );

            return ApiResponse::success([
                'payment'           => $payment,
                'booking_reference' => $payment->booking?->booking_reference,
                'payment_status'    => $payment->status->value,
                'message'           => 'Flight payment verified and booking confirmed successfully.',
            ]);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * Pay booking via B2B agent/distributor wallet (double-entry ledger debit).
     * POST /api/v1/flight/bookings/{identifier}/pay-wallet
     */
    public function payWithWallet(Request $request, string $identifier): JsonResponse
    {
        try {
            $tenant = $this->resolveTenant($request);
            $user = $request->user();

            $payment = $this->paymentService->payWithWallet(
                $identifier,
                $request->all(),
                $tenant,
                $user
            );

            return ApiResponse::success([
                'payment'           => $payment,
                'booking_reference' => $payment->booking?->booking_reference,
                'payment_status'    => $payment->status->value,
                'message'           => 'Wallet payment completed and booking confirmed.',
            ]);
        } catch (InsufficientBalanceException $e) {
            return ApiResponse::errors([
                'requested_amount' => $e->requestedAmount,
                'available_credit' => $e->availableCredit,
                'balance'          => $e->balance,
                'credit_limit'     => $e->creditLimit,
            ], 'Insufficient wallet balance / credit limit.', 422);
        } catch (DomainException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 500);
        }
    }

    /**
     * Get payment status and details for polling.
     * GET /api/v1/flight/payments/{reference}
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        try {
            $tenant = $this->resolveTenant($request);
            $payment = $this->paymentService->getPayment($reference, $tenant);

            return ApiResponse::success($payment);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, 'Flight payment session not found.', 404);
        }
    }

    /**
     * Get payment options, breakdown, and wallet purchasing power for a booking.
     * GET /api/v1/flight/bookings/{identifier}/payment-options
     */
    public function paymentOptions(Request $request, string $identifier): JsonResponse
    {
        try {
            $tenant = $this->resolveTenant($request);
            $user = $request->user();

            $options = $this->paymentService->getPaymentOptions($identifier, $tenant, $user);

            return ApiResponse::success($options);
        } catch (\Throwable $e) {
            return ApiResponse::errors(null, $e->getMessage(), 404);
        }
    }

    /**
     * Webhook receiver for payment gateways.
     * POST /api/v1/flight/payments/webhook/{gateway}
     */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        try {
            $signature = $request->header('X-Razorpay-Signature') ?? $request->header('Signature');
            $rawBody = (string) $request->getContent();

            $result = $this->paymentService->processWebhook(
                $gateway,
                $request->all(),
                $rawBody,
                $signature
            );

            return response()->json($result);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Internal webhook error.'], 500);
        }
    }

    protected function resolveTenant(Request $request): ?Tenant
    {
        if (app()->bound('tenant') && ($t = app('tenant')) instanceof Tenant) {
            return $t;
        }

        if ($tid = $request->header('X-Tenant-ID') ?: $request->input('tenant_id')) {
            return Tenant::find($tid);
        }

        return $request->user()?->tenant;
    }
}
