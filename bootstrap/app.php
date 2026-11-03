<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']]
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web([
            \Illuminate\Http\Middleware\HandleCors::class,
            'checkInstalled',
            \Modules\Saas\Http\Middleware\ResolveTenantFromDomain::class,
        ]);
        $middleware->api([
            \Illuminate\Http\Middleware\HandleCors::class,
            \Modules\Saas\Http\Middleware\ResolveTenantFromDomain::class,
            \Modules\Core\Http\Middleware\MeasureApiPerformance::class,
            \Modules\Core\Http\Middleware\SecurityHeaders::class,
            \Modules\Core\Http\Middleware\AddRequestId::class,
        ]);
        $middleware->alias([
            'compress' => \App\Http\Middleware\CompressResponse::class,
            'tenant.feature' => \Modules\Saas\Http\Middleware\EnsureTenantPlanFeature::class,
            'partner.auth' => \Modules\Aggregator\Http\Middleware\AuthenticatePartnerRequest::class,
            'partner.audit' => \Modules\Aggregator\Http\Middleware\AuditPartnerRequest::class,
            'partner.idempotency' => \Modules\Aggregator\Http\Middleware\EnsurePartnerIdempotency::class,
            'partner.scope' => \Modules\Aggregator\Http\Middleware\RequirePartnerScope::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->expectsJson() || $request->is('api/*') || $request->is('v1/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                $route = $request->route();
                $isCustomerAppRoute = is_object($route)
                    && collect($route->gatherMiddleware())->contains(
                        \Modules\Saas\Http\Middleware\ResolveCustomerAppContext::class
                    );
                if ($isCustomerAppRoute) {
                    return ApiResponse::errors(
                        errors: null,
                        message: 'Your session has expired. Please sign in again.',
                        code: Response::HTTP_UNAUTHORIZED,
                        data: ['code' => 'CUSTOMER_AUTH_REQUIRED'],
                    );
                }

                return response()->json([
                    'message' => 'Unauthenticated.',
                ], Response::HTTP_UNAUTHORIZED);
            }

            return null;
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (app()->isProduction() && $request->wantsJson() && $exception->getPrevious() instanceof ModelNotFoundException) {
                return ApiResponse::errors(
                    errors: null,
                    message: __('core::exceptions.model_not_found'),
                    code: Response::HTTP_NOT_FOUND
                );
            }

            return null;
        });

        // Database constraint failures (deleting a record still in use, a
        // duplicate, a missing required value) otherwise surface as a bare
        // "Server Error"; say what happened in terms an admin can act on.
        $exceptions->render(function (QueryException $exception, Request $request) {
            if (! ($request->is('api/*') || $request->is('v1/*') || $request->wantsJson())) {
                return null;
            }

            [$message, $status] = match ((int) ($exception->errorInfo[1] ?? 0)) {
                1451 => ['This record is still used elsewhere (for example by routes, inventory or bookings), so it can\'t be deleted. Remove or update those first, or mark it Inactive instead.', Response::HTTP_CONFLICT],
                1452 => ['One of the selected items no longer exists. Refresh the page and choose it again.', Response::HTTP_UNPROCESSABLE_ENTITY],
                1062 => ['A record with these details already exists.', Response::HTTP_CONFLICT],
                1048 => ['A required value is missing. Please fill in all required fields and try again.', Response::HTTP_UNPROCESSABLE_ENTITY],
                1406 => ['One of the values is too long. Please shorten it and try again.', Response::HTTP_UNPROCESSABLE_ENTITY],
                default => [null, null],
            };

            return $message === null ? null : ApiResponse::errors(errors: null, message: $message, code: $status);
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->wantsJson()) {
                return ApiResponse::errors(
                    errors: $exception->errors(),
                    message: __('core::exceptions.unprocessable'),
                    code: Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        });
    })
    ->create();
