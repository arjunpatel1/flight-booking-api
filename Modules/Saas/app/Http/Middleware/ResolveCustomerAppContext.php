<?php

namespace Modules\Saas\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\CustomerApp\CustomerAppSessionService;
use Modules\Saas\Support\CustomerAppContext;
use Modules\Saas\Support\TenantContext;
use Modules\Support\ApiResponse;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ResolveCustomerAppContext
{
    public function __construct(
        private readonly CustomerAppSessionService $sessions,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Queue and HTTP workers are long lived. Clear any stale request state
        // before resolving the signed application session, including on an
        // early fail-closed response.
        $this->tenantContext->clear();
        app()->forgetInstance(CustomerAppContext::class);

        try {
            $authenticated = $request->user();
            $customer = $authenticated instanceof User ? $authenticated : null;
            // Browser QR ordering uses the customer's Sanctum token, not a
            // mobile-app registration token.  A customer token is already
            // bound to one tenant; derive all authoritative context from that
            // token and reject arbitrary tenant/branch claims.
            $customerToken = $customer?->currentAccessToken();
            $isBrowserCustomer = $customer
                && $customerToken
                // Staff tokens may have wildcard abilities. Only explicitly
                // issued customer tokens may use browser customer resolution.
                && in_array('customer', $customerToken->abilities ?? [], true);
            if (blank($request->header('X-NexDine-Customer-App-Token'))
                && $isBrowserCustomer) {
                $this->resolveBrowserCustomer($request, $customer);
            } else {
                $session = $this->sessions->resolve(
                    trim((string) $request->header('X-NexDine-Customer-App-Token')),
                    $customer,
                );
                $registration = $session->registration;
                $tenant = $registration->tenant;
                if (! $tenant) {
                    throw new CustomerAppAuthorizationException('TENANT_UNAVAILABLE', 'Restaurant is unavailable.');
                }

                $branchId = $this->assertUnspoofedIdentity(
                    $request,
                    $registration->uuid,
                    (int) $tenant->id,
                    $customer?->getKey(),
                    (string) $tenant->domain,
                );
                $branch = $branchId === null ? null : Branch::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereKey($branchId)
                    ->first();
                if ($branchId !== null && ! $branch) {
                    throw new CustomerAppAuthorizationException('BRANCH_FORBIDDEN', 'Branch is unavailable.');
                }

                $context = new CustomerAppContext(
                    appRegistration: $registration,
                    tenant: $tenant,
                    customer: $customer,
                    branch: $branch,
                    installationId: $session->installation_id,
                );

                $this->tenantContext->set($tenant);
                app()->instance(CustomerAppContext::class, $context);
                $request->attributes->set(CustomerAppContext::class, $context);
                $request->attributes->set('tenant_id', $context->tenantId());
                $request->attributes->set('customer_id', $context->customerId());
                $request->attributes->set('app_id', $registration->uuid);
                $request->attributes->set('branch_id', $branch?->getKey());
                // Authoritative values replace spoofable request fields for legacy
                // customer controllers while they migrate to the immutable context.
                $authoritative = [
                    'tenant_id' => $context->tenantId(),
                    'customer_id' => $context->customerId(),
                    'app_id' => $registration->uuid,
                ];
                if ($branch) {
                    $authoritative['branch_id'] = $branch->getKey();
                }
                $request->merge($authoritative);
            }
        } catch (CustomerAppAuthorizationException $exception) {
            $this->tenantContext->clear();
            app()->forgetInstance(CustomerAppContext::class);

            return $this->unavailable($exception->httpStatus === 401 ? 401 : 403);
        } catch (Throwable $exception) {
            $this->tenantContext->clear();
            app()->forgetInstance(CustomerAppContext::class);
            report($exception);

            return ApiResponse::errors(null, 'Restaurant services are temporarily offline. Please retry shortly.', 503, [
                'code' => 'CUSTOMER_APP_TEMPORARILY_UNAVAILABLE',
            ]);
        }

        try {
            // Downstream validation and domain exceptions must retain their
            // normal API semantics; only context resolution is fail-closed.
            return $next($request);
        } finally {
            $this->tenantContext->clear();
            app()->forgetInstance(CustomerAppContext::class);
        }
    }

    protected function resolveBrowserCustomer(Request $request, User $customer): void
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->find($customer->tenant_id);
        if (! $tenant) {
            throw new CustomerAppAuthorizationException('TENANT_UNAVAILABLE', 'Restaurant is unavailable.');
        }

        $branchId = $this->browserBranchId($request, (int) $tenant->id);
        $this->tenantContext->set($tenant);
        $request->attributes->set('tenant_id', (int) $tenant->id);
        $request->attributes->set('customer_id', (int) $customer->id);
        if ($branchId !== null) {
            $request->attributes->set('branch_id', $branchId);
        }
        $request->merge(array_filter([
            'tenant_id' => (int) $tenant->id,
            'customer_id' => (int) $customer->id,
            'branch_id' => $branchId,
        ], static fn ($value) => $value !== null));

    }

    private function browserBranchId(Request $request, int $tenantId): ?int
    {
        return $this->resolveBranchId($request, $tenantId);
    }

    private function unavailable(int $status): JsonResponse
    {
        return ApiResponse::errors(null, 'Online customer ordering is not enabled for this restaurant plan.', $status, [
            'code' => 'CUSTOMER_APP_UNAVAILABLE',
        ]);
    }

    private function assertUnspoofedIdentity(
        Request $request,
        string $appUuid,
        int $tenantId,
        ?int $customerId,
        string $tenantDomain,
    ): ?int {
        $this->assertAliases($request, ['tenant_id', 'tenantId', 'restaurant_id', 'restaurantId'],
            ['X-Tenant-ID', 'X-Tenant-Id', 'X-Restaurant-ID'], (string) $tenantId, 'TENANT_MISMATCH');
        $this->assertAliases($request, ['app_id', 'appId'], [], $appUuid, 'APP_MISMATCH');

        $suppliedDomain = strtolower(trim((string) $request->header('X-NexDine-Tenant-Domain')));
        if ($suppliedDomain !== '') {
            $expectedDomain = strtolower(trim($tenantDomain));
            if ($expectedDomain === '' || ! hash_equals($expectedDomain, $suppliedDomain)) {
                throw new CustomerAppAuthorizationException('TENANT_MISMATCH', 'Client tenant domain is not authoritative.');
            }
        }

        $customerValues = $this->values($request, ['customer_id', 'customerId'], []);
        if ($customerValues !== [] && ($customerId === null || count(array_unique($customerValues)) !== 1
            || ! hash_equals((string) $customerId, $customerValues[0]))) {
            throw new CustomerAppAuthorizationException('CUSTOMER_FORBIDDEN', 'Customer identity is not authoritative.');
        }

        return $this->resolveBranchId($request, $tenantId);
    }

    private function resolveBranchId(Request $request, int $tenantId): ?int
    {
        $legacy = $this->values($request, ['branch_id', 'branchId'], ['X-Branch-ID']);
        $references = $this->values(
            $request,
            ['branch_reference', 'branchReference'],
            ['X-Branch-Reference'],
        );
        if ($legacy === [] && $references === []) return null;

        if (($legacy !== [] && (count(array_unique($legacy)) !== 1 || ! ctype_digit($legacy[0]) || (int) $legacy[0] < 1))
            || ($references !== [] && (count(array_unique($references)) !== 1 || ! Str::isUuid($references[0])))) {
            throw new CustomerAppAuthorizationException('BRANCH_FORBIDDEN', 'Branch identity is invalid.');
        }

        $branch = Branch::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($legacy !== [], fn ($query) => $query->whereKey((int) $legacy[0]))
            ->when($references !== [], fn ($query) => $query->where('uuid', $references[0]))
            ->first();
        if (! $branch) {
            throw new CustomerAppAuthorizationException('BRANCH_FORBIDDEN', 'Branch is unavailable.');
        }

        return (int) $branch->id;
    }

    private function assertAliases(Request $request, array $fields, array $headers, string $expected, string $code): void
    {
        $values = $this->values($request, $fields, $headers);
        if ($values !== [] && (count(array_unique($values)) !== 1 || ! hash_equals($expected, $values[0]))) {
            throw new CustomerAppAuthorizationException($code, 'Client identity is not authoritative.');
        }
    }

    private function values(Request $request, array $fields, array $headers): array
    {
        $values = [];
        foreach ($fields as $field) {
            if ($request->exists($field) && ! blank($request->input($field))) {
                $values[] = trim((string) $request->input($field));
            }
        }
        foreach ($headers as $header) {
            if ($request->headers->has($header) && ! blank($request->header($header))) {
                $values[] = trim((string) $request->header($header));
            }
        }

        return $values;
    }
}
