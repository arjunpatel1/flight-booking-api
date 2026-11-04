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
     * Plans are assigned by the platform (Tenant Subscriptions), never by the
     * tenant itself: these endpoints used to let a tenant subscribe, switch,
     * renew or cancel its own plan without any payment.
     * POST /api/v1/flight/subscriptions/{subscribe|upgrade|downgrade|renew|cancel}
     */
    public function subscribe(): JsonResponse
    {
        return $this->planChangesAreManagedByPlatform();
    }

    public function upgrade(): JsonResponse
    {
        return $this->planChangesAreManagedByPlatform();
    }

    public function downgrade(): JsonResponse
    {
        return $this->planChangesAreManagedByPlatform();
    }

    public function renew(): JsonResponse
    {
        return $this->planChangesAreManagedByPlatform();
    }

    public function cancel(): JsonResponse
    {
        return $this->planChangesAreManagedByPlatform();
    }

    protected function planChangesAreManagedByPlatform(): JsonResponse
    {
        return ApiResponse::errors(
            null,
            'Plan changes are managed by CRT Travels. Use "Request Upgrade" on your profile page to ask for a different plan or a renewal.',
            403
        );
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
