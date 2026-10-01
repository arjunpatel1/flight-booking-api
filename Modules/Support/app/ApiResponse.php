<?php

namespace Modules\Support;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponse
{
    /**
     * Exception response handler,
     * Usage: this method usually used in catch(Exception $exception) block.
     *
     * @param Exception $exception
     * @param int $code
     * @param bool $log
     * @return JsonResponse
     */
    public static function exception(Exception $exception, int $code = Response::HTTP_INTERNAL_SERVER_ERROR, bool $log = true): JsonResponse
    {
        if ($exception->getCode() === Response::HTTP_UNPROCESSABLE_ENTITY) {
            return self::errors(null, $exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($log) {
            Log::error($exception->getMessage());
        }

        $message = app()->isProduction() ? __('admin::messages.something_went_wrong') : $exception->getMessage();
        return self::errors(null, $message, $code);
    }

    /**
     * Error response handler
     *
     * @param mixed $errors
     * @param string|null $message
     * @param int $code
     * @param mixed|null $data
     * @return JsonResponse
     */
    public static function errors(mixed $errors, string|null $message = null, int $code = Response::HTTP_BAD_REQUEST, mixed $data = null): JsonResponse
    {
        return self::response($data, $message, $errors, $code);
    }

    /**
     * Response handler
     *
     * @param mixed $body
     * @param string|null $message
     * @param mixed $errors
     * @param int $code
     *
     * @return JsonResponse
     */
    private static function response(mixed $body, string|null $message, mixed $errors, int $code): JsonResponse
    {
        return response()->json(array_filter([
            "body" => $body,
            "message" => is_null($message) && !is_null($errors) && count($errors) > 0
                ? (is_array($errors) ? Arr::first($errors) : $errors)
                : $message,
            "errors" => $errors,
        ], fn($value) => !is_null($value)), $code);
    }

    /**
     * Success paginator response handler
     *
     * @param LengthAwarePaginator $paginator
     * @param string|null $resource
     * @param array|null $filters
     * @param array|null $extraData
     * @return JsonResponse
     */
    public static function pagination(LengthAwarePaginator $paginator,
                                      ?string              $resource = null,
                                      ?array               $filters = null,
                                      ?array               $defaultFilters = null): JsonResponse
    {
        if ($resource && method_exists($resource, 'collection')) {
            $records = $resource::collection($paginator);
        } else {
            $records = $paginator->items();
        }

        $data = [
            'data' => $records,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ]
        ];

        if (!is_null($filters)) {
            $data['filters'] = $filters;
        }

        if (!is_null($defaultFilters)) {
            $data['default_filters'] = $defaultFilters;
        }

        return self::success($data);
    }

    /**
     * Success response handler
     *
     * @param mixed $body
     * @param string|null $message
     * @param int $code
     *
     * @return JsonResponse
     */
    public static function success(mixed $body = null, string|null $message = null, int $code = Response::HTTP_OK): JsonResponse
    {
        return self::response($body, $message, null, $code);
    }

    /**
     * Successfully created response handler
     *
     * @param mixed $body
     * @param string|null $resource
     * @param string|null $message
     * @param int $code
     * @return JsonResponse
     */
    public static function created(mixed $body = null, ?string $resource = null, ?string $message = null, int $code = Response::HTTP_CREATED): JsonResponse
    {
        return self::success(
            body: $body,
            message: $message ?: __("admin::messages.resource_created", ["resource" => $resource]),
            code: $code
        );
    }

    /**
     * Successfully updated response handler
     *
     * @param mixed $body
     * @param string|null $resource
     * @param string|null $message
     * @param int $code
     * @return JsonResponse
     */
    public static function updated(mixed $body, ?string $resource = null, ?string $message = null, int $code = Response::HTTP_OK): JsonResponse
    {
        return self::success(
            body: $body,
            message: $message ?: __("admin::messages.resource_updated", ["resource" => $resource]),
            code: $code
        );
    }

    /**
     * Destroy response handler
     *
     * @param bool $destroyed
     * @param string|null $resource
     * @param string|null $message
     * @param int $successCode
     * @param int $errorCode
     * @return JsonResponse
     */
    public static function destroyed(bool $destroyed, ?string $resource = null, ?string $message = null, int $successCode = Response::HTTP_OK, int $errorCode = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        if ($destroyed) {
            return ApiResponse::success(
                message: __('admin::messages.resource_deleted', ['resource' => $resource]),
                code: $successCode
            );
        }

        return ApiResponse::errors(
            errors: null,
            message: $message ?: __('admin::messages.resource_not_found', ['resource' => $resource]),
            code: $errorCode
        );
    }
}
