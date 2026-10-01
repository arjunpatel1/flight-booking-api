<?php

namespace Modules\Aggregator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Aggregator\Support\PartnerApiResponse;
use Symfony\Component\HttpFoundation\Response;

class RequirePartnerScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $context = $request->attributes->get('partner_context');

        if (! $context || ! $context->hasScope($scope)) {
            return PartnerApiResponse::error(
                'INSUFFICIENT_SCOPE',
                "This endpoint requires the {$scope} scope.",
                403,
                ['required_scope' => $scope],
            );
        }

        return $next($request);
    }
}
