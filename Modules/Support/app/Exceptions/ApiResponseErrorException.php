<?php

namespace Modules\Support\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseErrorException extends Exception implements ShouldntReport
{
    public function __construct(string $message, string $code = Response::HTTP_BAD_REQUEST)
    {
        parent::__construct($message, $code);
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
            message: $this->getMessage(),
            code: $this->getCode(),
        );
    }
}
