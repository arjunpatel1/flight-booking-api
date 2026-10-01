<?php

namespace Modules\Order\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Order\Delivery\ProviderUnavailable;
use Modules\Order\Delivery\UengageWebhookProcessor;

final class UengageWebhookController extends Controller
{
    public function __invoke(Request $request, UengageWebhookProcessor $processor): JsonResponse
    {
        try { $result = $processor->process($request->getContent(), $request->headers->all()); }
        catch (ProviderUnavailable $exception) {
            $authFailure = $exception->reasonCode === 'WEBHOOK_AUTHENTICATION_FAILED';
            return response()->json(['status' => false, 'message' => $authFailure ? 'Unauthorized' : 'Webhook unavailable'], $authFailure ? 401 : 503);
        }
        Log::info('delivery.webhook_processed', ['provider' => 'uengage', 'operation' => 'status_callback',
            'delivery_id' => $result['delivery_id'], 'order_id' => $result['order_id'], 'tenant_id' => $result['tenant_id'],
            'status' => $result['status'], 'duplicate' => $result['duplicate'], 'result' => 'accepted']);
        return response()->json(['status' => true, 'message' => 'Webhook Processed']);
    }
}
