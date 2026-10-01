<?php

namespace Modules\Support\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Modules\Support\ApiResponse;

class ResourceNotFoundException extends Exception
{
    public function __construct(string $resource)
    {
        parent::__construct(
            message: __('admin::messages.resource_not_found', ['resource' => $resource])
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
            message: $this->getMessage(),
            code: 400
        );
    }
}
