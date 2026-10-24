<?php

namespace Modules\Flight\Http\Controllers\Api\V1;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Flight\Exceptions\InvalidPlanException;
use Modules\Flight\Exceptions\QuotaExceededException;
use Modules\Flight\Exceptions\SubscriptionExpiredException;
use Modules\Flight\Models\FlightSubscriptionOverage;
use Modules\Flight\Services\Subscription\FlightSubscriptionQuotaService;
use Modules\Support\ApiResponse;

class FlightSubscriptionController extends Controller
{
    public function __construct(
        protected FlightSubscriptionQuotaService $subscriptionService
    ) {}

    protected function resolveTenantId(Request $request): ?int
    {
        return $request->user()?->tenant_id ?? auth()->user()?->tenant_id;
    }

    /**
     * List all available SaaS subscription plans.
     * GET /api/v1/flight/subscriptions/plans
     */
    public function plans(): JsonResponse
    {
        $plans = $this->subscriptionService->getPlans();
        return ApiResponse::success($plans);
    }

    /**
     * Get current tenant's subscription status, active entitlements, and quotas.
     * GET /api/v1/flight/subscriptions/current
     */
    public function current(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        if (! $tenantId) {
            return ApiResponse::errors(null, 'Tenant context is required.', 400);
        }

        $summary = $this->subscriptionService->getSummary($tenantId);
        return ApiResponse::success($summary);
    }

    /**
     * Subscribe tenant to a plan.
     * POST /api/v1/flight/subscriptions/subscribe
     */
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'plan_code'       => 'required|string',
            'duration_months' => 'sometimes|integer|min:1|max:36',
        ]);

        $tenantId = $this->resolveTenantId($request);
        if (! $tenantId) {
            return ApiResponse::errors(null, 'Tenant context is required.', 400);
        }

        try {
            $subscription = $this->subscriptionService->subscribe(
                tenant: $tenantId,
                planCode: $request->string('plan_code'),
                options: ['duration_months' => $request->integer('duration_months', 1)]
            );

            return ApiResponse::success([
                'subscription' => $subscription,
                'summary'      => $this->subscriptionService->getSummary($tenantId),
            ], 'Subscribed successfully.', 201);
        } catch (InvalidPlanException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 404);
        } catch (Exception $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    /**
     * Upgrade subscription to a higher tier.
     * POST /api/v1/flight/subscriptions/upgrade
     */
    public function upgrade(Request $request): JsonResponse
    {
        $request->validate([
            'plan_code' => 'required|string',
        ]);

        $tenantId = $this->resolveTenantId($request);
        if (! $tenantId) {
            return ApiResponse::errors(null, 'Tenant context is required.', 400);
        }

        try {
            $subscription = $this->subscriptionService->upgrade($tenantId, $request->string('plan_code'));

            return ApiResponse::success([
                'subscription' => $subscription,
                'summary'      => $this->subscriptionService->getSummary($tenantId),
            ], 'Subscription upgraded successfully.');
        } catch (InvalidPlanException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 404);
        } catch (Exception $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    /**
     * Downgrade subscription to a lower tier.
     * POST /api/v1/flight/subscriptions/downgrade
     */
    public function downgrade(Request $request): JsonResponse
    {
        $request->validate([
            'plan_code' => 'required|string',
        ]);

        $tenantId = $this->resolveTenantId($request);
        if (! $tenantId) {
            return ApiResponse::errors(null, 'Tenant context is required.', 400);
        }

        try {
            $subscription = $this->subscriptionService->downgrade($tenantId, $request->string('plan_code'));

            return ApiResponse::success([
                'subscription' => $subscription,
                'summary'      => $this->subscriptionService->getSummary($tenantId),
            ], 'Subscription downgraded successfully.');
        } catch (InvalidPlanException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 404);
        } catch (Exception $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    /**
     * Renew subscription.
     * POST /api/v1/flight/subscriptions/renew
     */
    public function renew(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        if (! $tenantId) {
            return ApiResponse::errors(null, 'Tenant context is required.', 400);
        }

        try {
            $subscription = $this->subscriptionService->renew($tenantId);

            return ApiResponse::success([
                'subscription' => $subscription,
                'summary'      => $this->subscriptionService->getSummary($tenantId),
            ], 'Subscription renewed successfully.');
        } catch (SubscriptionExpiredException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 400);
        } catch (Exception $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    /**
     * Cancel subscription.
     * POST /api/v1/flight/subscriptions/cancel
     */
    public function cancel(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        if (! $tenantId) {
            return ApiResponse::errors(null, 'Tenant context is required.', 400);
        }

        try {
            $subscription = $this->subscriptionService->cancel($tenantId);

            return ApiResponse::success([
                'subscription' => $subscription,
            ], 'Subscription cancelled successfully.');
        } catch (Exception $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    /**
     * Get all quota limits and usage counters.
     * GET /api/v1/flight/subscriptions/quotas
     */
    public function quotas(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        if (! $tenantId) {
            return ApiResponse::errors(null, 'Tenant context is required.', 400);
        }

        $quotas = [];
        foreach (FlightSubscriptionQuotaService::ALL_METRICS as $metric) {
            $quotas[$metric] = $this->subscriptionService->getQuota($tenantId, $metric);
        }

        return ApiResponse::success($quotas);
    }

    /**
     * Consume units for a metric (enforcing quota / logging overage).
     * POST /api/v1/flight/subscriptions/quotas/{metric}/consume
     */
    public function consume(Request $request, string $metric): JsonResponse
    {
        $request->validate([
            'units'     => 'sometimes|integer|min:1',
            'actor'     => 'sometimes|string|max:100',
            'reference' => 'sometimes|string|max:100',
        ]);

        $tenantId = $this->resolveTenantId($request);
        if (! $tenantId) {
            return ApiResponse::errors(null, 'Tenant context is required.', 400);
        }

        $units = $request->integer('units', 1);

        try {
            $quota = $this->subscriptionService->recordUsage(
                tenant: $tenantId,
                metric: $metric,
                units: $units,
                actor: $request->input('actor', 'API'),
                reference: $request->input('reference')
            );

            return ApiResponse::success($quota, 'Quota consumed successfully.');
        } catch (QuotaExceededException $e) {
            return ApiResponse::errors(null, $e->getMessage(), 403);
        } catch (Exception $e) {
            return ApiResponse::errors(null, $e->getMessage(), 422);
        }
    }

    /**
     * Get overage charges and history.
     * GET /api/v1/flight/subscriptions/overages
     */
    public function overages(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        if (! $tenantId) {
            return ApiResponse::errors(null, 'Tenant context is required.', 400);
        }

        $overages = FlightSubscriptionOverage::where('tenant_id', $tenantId)
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::pagination($overages);
    }

    /**
     * Render frontend subscription workspace.
     * GET /api/v1/flight/subscriptions/workspace
     */
    public function workspace(Request $request)
    {
        $tenantId = $this->resolveTenantId($request) ?? 1;
        $summary = $this->subscriptionService->getSummary($tenantId);
        $plans = $this->subscriptionService->getPlans();

        if ($request->wantsJson()) {
            return ApiResponse::success([
                'summary' => $summary,
                'plans'   => $plans,
            ]);
        }

        return view('flight::subscription.workspace', [
            'summary' => $summary,
            'plans'   => $plans,
        ]);
    }
}
