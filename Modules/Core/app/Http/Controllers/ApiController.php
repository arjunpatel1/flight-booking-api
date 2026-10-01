<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class ApiController extends Controller
{
    protected function success(mixed $body = null, ?string $message = null, int $code = Response::HTTP_OK): JsonResponse
    {
        return ApiResponse::success($body, $message, $code);
    }

    protected function responseSuccess(mixed $body = null, ?string $message = null, int $code = Response::HTTP_OK): JsonResponse
    {
        return $this->success($body, $message, $code);
    }
}
