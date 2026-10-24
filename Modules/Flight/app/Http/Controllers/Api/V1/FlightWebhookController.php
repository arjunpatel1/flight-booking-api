<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Modules\Core\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Flight\Models\FlightWebhookEvent;
use Modules\Flight\Services\Webhook\FlightWebhookService;
use Throwable;

class FlightWebhookController extends Controller
{
    public function __construct(
        protected FlightWebhookService $webhookService
    ) {}

    /**
     * Public incoming webhook handler for configured external providers.
     * POST /api/v1/flight/webhooks/{provider} or POST /api/v1/webhooks/{provider}
     */
    public function handleWebhook(Request $request, string $provider): JsonResponse
    {
        $rawBody = $request->getContent() ?: json_encode($request->all());
        $payload = $request->all();
        $headers = $request->headers->all();

        try {
            $result = $this->webhookService->handleIncoming($provider, $payload, $rawBody, $headers);

            return response()->json([
                'success' => true,
                'provider' => $provider,
                'data'    => $result,
            ], 200);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Webhook processing failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show webhook event audit record.
     * GET /api/v1/flight/webhooks/{identifier}
     */
    public function showWebhook(Request $request, string $identifier): JsonResponse
    {
        $tenantId = $request->user()?->tenant_id;

        $event = FlightWebhookEvent::where('id', is_numeric($identifier) ? (int) $identifier : 0)
            ->orWhere('event_id', $identifier)
            ->first();

        if (! $event) {
            return response()->json(['error' => 'Webhook record not found.'], 404);
        }

        if ($tenantId && $event->tenant_id && $event->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Unauthorized access to webhook record.'], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => $event,
        ]);
    }
}
