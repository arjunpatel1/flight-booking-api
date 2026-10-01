<?php

namespace Modules\User\Http\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

/**
 * Resolves the authenticated customer behind a `customer-app/*` request.
 *
 * `ResolveCustomerAppContext` has already established the authoritative tenant
 * on the request attributes; this only accepts a sanctum user that belongs to
 * that same tenant and holds the customer role, so one tenant's token can never
 * read another tenant's data.
 */
trait ResolvesAppCustomer
{
    /**
     * Resolve a customer when the endpoint also permits anonymous guests.
     *
     * An absent identity is intentionally different from an invalid identity:
     * callers that send a token must still pass the same tenant, role and
     * account-state checks as authenticated customer endpoints.
     */
    protected function optionalCustomerForRequest(Request $request): ?User
    {
        if (! $request->user()) {
            return null;
        }

        return $this->customerForRequest($request);
    }

    /**
     * The customer for this request, or abort.
     */
    protected function customerForRequest(Request $request): User
    {
        /** @var User|null $user */
        $user = $request->user();
        $tenantId = (int) $request->attributes->get('tenant_id');

        abort_unless(
            $user
            && $tenantId > 0
            && (int) $user->tenant_id === $tenantId
            && $user->hasRole(DefaultRole::Customer->value),
            Response::HTTP_FORBIDDEN,
            'This customer session does not belong to this restaurant.',
        );

        abort_unless(
            $user->is_active && $user->can_login,
            Response::HTTP_FORBIDDEN,
            'This customer account is disabled. Contact the restaurant.',
        );

        return $user;
    }
}
