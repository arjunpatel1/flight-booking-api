<?php

namespace Modules\Payment\Http\Controllers\Api\V1;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Order\Models\Order;
use Modules\Payment\Models\TenantPaymentGatewayConfig;
use Modules\Payment\Services\DirectUpiService;
use Modules\Payment\Services\DirectUpiWebhookVerifier;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class DirectUpiController extends Controller
{
    /** Create a customer-facing UPI session from an unguessable order reference. */
    public function createPublic(Request $request, DirectUpiService $service): JsonResponse
    {
        $data = $request->validate([
            'order_reference' => ['required', 'string', 'regex:/^ORD-[A-Z0-9]{20}$/'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);
        $tenantId = PublicTenantGuard::tenantId($request);
        $customerId = (int) $request->user()->id;
        $order = Order::query()->withOutGlobalBranchPermission()
            ->where('reference_no', $data['order_reference'])
            ->where('customer_id', $customerId)
            ->whereIn('branch_id', \DB::table('branches')->select('id')->where('tenant_id', $tenantId))
            ->firstOrFail();
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        abort_unless(strlen($idempotencyKey) >= 16 && strlen($idempotencyKey) <= 120, Response::HTTP_UNPROCESSABLE_ENTITY, 'A valid Idempotency-Key header is required.');

        try {
            $session = $service->create($tenantId, $customerId, $order->id, $idempotencyKey, (int) ($data['expires_in_minutes'] ?? 10));
        } catch (UniqueConstraintViolationException) {
            $session = \Modules\Payment\Models\TenantPaymentSession::query()
                ->where('tenant_id', $tenantId)->where('provider', 'direct_upi')
                ->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        return ApiResponse::success($this->present($session));
    }

    /** Poll a customer payment session by its non-guessable UUID reference. */
    public function showPublic(Request $request, string $reference): JsonResponse
    {
        $session = \Modules\Payment\Models\TenantPaymentSession::query()
            ->where('tenant_id', PublicTenantGuard::tenantId($request))
            ->where('created_by', (int) $request->user()->id)
            ->where('reference', $reference)->firstOrFail();

        return ApiResponse::success($this->present($session));
    }

    public function create(Request $request, DirectUpiService $service): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $tenantId = $request->user()?->tenantId();
        abort_if(! $tenantId, Response::HTTP_FORBIDDEN);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        abort_unless(strlen($idempotencyKey) >= 16 && strlen($idempotencyKey) <= 120, Response::HTTP_UNPROCESSABLE_ENTITY, 'A valid Idempotency-Key header is required.');

        try {
            $session = $service->create((int) $tenantId, (int) $request->user()->id, (int) $data['order_id'], $idempotencyKey, (int) ($data['expires_in_minutes'] ?? 10));
        } catch (UniqueConstraintViolationException) {
            $session = \Modules\Payment\Models\TenantPaymentSession::query()
                ->where('tenant_id', $tenantId)->where('provider', 'direct_upi')
                ->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        return ApiResponse::success($this->present($session));
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $tenantId = $request->user()?->tenantId();
        abort_if(! $tenantId, Response::HTTP_FORBIDDEN);
        $session = \Modules\Payment\Models\TenantPaymentSession::query()
            ->where('tenant_id', $tenantId)->where('reference', $reference)->firstOrFail();

        return ApiResponse::success($this->present($session));
    }

    public function webhook(Request $request, string $webhookKey, DirectUpiService $service, DirectUpiWebhookVerifier $verifier): JsonResponse
    {
        $config = TenantPaymentGatewayConfig::query()->withoutGlobalTenant()
            ->where('provider', 'direct_upi')->where('webhook_key', $webhookKey)->firstOrFail();
        $secret = (string) data_get($config->credentials, 'webhook_secret');
        $raw = $verifier->verify($request, $secret);
        $deliveryId = trim((string) $request->header('X-Webhook-Id'));

        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid JSON payload.');
        }
        abort_unless(is_array($payload), Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if($deliveryId !== '' && ! hash_equals($deliveryId, (string) ($payload['id'] ?? '')), Response::HTTP_UNPROCESSABLE_ENTITY, 'Webhook delivery ID does not match payload.');
        $service->processWebhook($config, $raw, $payload);

        return response()->json(['accepted' => true]);
    }

    private function present(\Modules\Payment\Models\TenantPaymentSession $session): array
    {
        return [
            'reference' => $session->reference,
            'amount' => number_format((float) $session->amount, 2, '.', ''),
            'currency' => $session->currency,
            'status' => $session->status,
            'intent_url' => $session->intent_url,
            'qr_data' => $session->qr_data,
            'transaction_reference' => $session->transaction_reference,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'finalized_at' => $session->finalized_at?->toIso8601String(),
        ];
    }
}
