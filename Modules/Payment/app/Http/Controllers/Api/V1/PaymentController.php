<?php

namespace Modules\Payment\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderPayment\OrderPaymentAuthorization;
use Modules\Support\InputLimit;
use Modules\Payment\Enums\PaymentMethod;
use Modules\Payment\Gateways\PaymentGatewayManager;
use Modules\Payment\Http\Requests\Api\V1\ComplimentaryPaymentRequest;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\Payment\PaymentServiceInterface;
use Modules\Payment\Services\PaymentAggregationService;
use Modules\Payment\Transformers\Api\V1\PaymentResource;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    /**
     * Create a new instance of PaymentController
     *
     * @param PaymentServiceInterface $service
     */
    public function __construct(
        protected PaymentServiceInterface $service,
        private readonly OrderPaymentAuthorization $authorization,
    ) {
    }

    /**
     * This method retrieves and returns a list of Payment models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: PaymentResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single Payment model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new PaymentResource($this->service->show($id))
        );
    }

    /**
     * Process payment for an order
     *
     * @param Request $request
     * @return JsonResponse
     */
    /**
     * List card-present terminal gateways that are configured and usable, so
     * the POS can offer "pay on terminal" only when a driver is available.
     */
    public function gateways(): JsonResponse
    {
        return ApiResponse::success([
            'gateways' => app(PaymentGatewayManager::class)->available(),
        ]);
    }

    public function summary(Request $request, PaymentAggregationService $aggregator): JsonResponse
    {
        return ApiResponse::success($aggregator->summarize($request->input('filters', [])));
    }

    public function process(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            // Bounded and precision-checked: an unbounded numeric let a client
            // post an arbitrarily large payment, and >3dp silently rounded.
            'amount' => ['required', ...InputLimit::money(min: 0.01)],
            'payment_method' => ['required', 'string', 'max:60'],
            'transaction_id' => 'nullable|string|max:255',
            'gateway' => ['nullable', Rule::in(['manual', 'pinelabs', 'razorpay', 'direct_upi'])],
            'gateway_data' => 'nullable|array',
            'gateway_data.terminal_id' => 'nullable|string|max:120',
            'gateway_data.payment_id' => 'nullable|string|max:120',
            'gateway_data.gateway_payment_id' => 'nullable|string|max:120',
            'gateway_data.razorpay_payment_id' => 'nullable|string|max:120',
            'gateway_data.razorpay_order_id' => 'nullable|string|max:120',
            'gateway_data.razorpay_signature' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        $payment = DB::transaction(function () use ($validated, $user) {
            $orderId = $validated['order_id'];
            $query = Order::query()->lockForUpdate();

            if (is_numeric($orderId)) {
                $order = $query->where('id', $orderId)->first();
            } else {
                $order = $query->where('reference_no', $orderId)->first();
            }

            if (!$order) {
                abort(Response::HTTP_NOT_FOUND, __('order::messages.order_not_found'));
            }

            $this->authorization->authorize($order, $user);

            $dueAmount = round($order->due_amount->amount(), 3);
            $paymentAmount = round((float)$validated['amount'], 3);

            if ($paymentAmount > $dueAmount) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('order::messages.payment_overpaid', [
                    'paid' => number_format($paymentAmount, 3),
                    'total' => number_format($dueAmount, 3),
                ]));
            }

            $method = $this->normalizePaymentMethod($validated['payment_method']);
            $allowedMethods = $order->branch?->payment_methods ?: [];

            if (!in_array($method, $allowedMethods, true)) {
                throw ValidationException::withMessages([
                    'payment_method' => __('validation.in', [
                        'attribute' => __('payment::payments.payment'),
                    ]),
                ]);
            }

            return $this->service->processPayment($order, [
                ...$validated,
                'method' => $method,
            ]);
        });

        return ApiResponse::created(
            body: new PaymentResource($payment),
            resource: $this->service->label()
        );
    }

    /**
     * Settle an order as a complimentary (no-charge / NC) bill. Records the
     * remaining due as a complimentary payment with an audit reason and marks the
     * order paid. Requires the dedicated complimentary permission (manager-level).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function complimentary(ComplimentaryPaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $payment = DB::transaction(function () use ($validated) {
            $orderId = $validated['order_id'];
            $query = Order::query()->lockForUpdate();

            $order = is_numeric($orderId)
                ? $query->where('id', $orderId)->first()
                : $query->where('reference_no', $orderId)->first();

            if (!$order) {
                abort(Response::HTTP_NOT_FOUND, __('order::messages.order_not_found'));
            }

            return $this->service->complimentaryPayment($order, $validated['reason']);
        });

        return ApiResponse::created(
            body: new PaymentResource($payment),
            resource: $this->service->label()
        );
    }

    /**
     * Refund payment
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refund(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_id' => 'required|integer|exists:payments,id',
            'reason' => 'required|string|max:500',
        ]);

        $payment = Payment::query()->findOrFail($validated['payment_id']);

        // Process refund
        $refund = $this->service->refundPayment($payment, $validated['reason']);

        return ApiResponse::success(
            body: new PaymentResource($refund)
        );
    }

    /**
     * Normalize frontend payment aliases to backend enum values.
     */
    private function normalizePaymentMethod(string $method): string
    {
        return match ($method) {
            'upi' => PaymentMethod::UPI->value,
            default => $method,
        };
    }
}
