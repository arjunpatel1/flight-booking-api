<?php

namespace Modules\User\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class TooManyLoginAttemptsException extends Exception
{
    public function __construct(int $retryAfter)
    {
        parent::__construct(
            message: __('auth.throttle', ['seconds' => $retryAfter]),
        );
    }

    /**
     * Report the exception.
     *
     * @return void
     */
    public function report(): void
    {
        // ...
    }

    /**
     * Render the exception into an HTTP response.
     *
     * @return JsonResponse
     */
    public function render(): JsonResponse
    {
        return ApiResponse::errors(
            errors: null,
            message: $this->message,
            code: Response::HTTP_TOO_MANY_REQUESTS
        );
    }
}
