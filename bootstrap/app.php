<?php

use Illuminate\Auth\AuthenticationException;
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
        ['prefix' => 'v1', 'middleware' => ['api', 'auth:sanctum']]
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
