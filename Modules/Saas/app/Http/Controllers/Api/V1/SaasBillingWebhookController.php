<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Services\Billing\SaasBillingWebhookService;
use Modules\Support\ApiResponse;

class SaasBillingWebhookController extends Controller
{
    public function razorpay(Request $request, SaasBillingWebhookService $webhooks): JsonResponse
    {
        return ApiResponse::success($webhooks->handleRazorpay($request), 'Razorpay billing webhook processed.');
    }

    public function stripe(Request $request, SaasBillingWebhookService $webhooks): JsonResponse
    {
        return ApiResponse::success($webhooks->handleStripe($request), 'Stripe billing webhook processed.');
    }
}
