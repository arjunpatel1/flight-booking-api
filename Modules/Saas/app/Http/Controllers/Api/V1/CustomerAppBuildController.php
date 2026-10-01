<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppBuild;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\CustomerApp\CustomerAppBuildService;
use Modules\Saas\Traits\ResolvesCurrentTenant;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerAppBuildController extends Controller
{
    use ResolvesCurrentTenant;

    public function __construct(private readonly CustomerAppBuildService $builds) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $tenant = $this->currentTenant($request);
            $this->assertAdministrator($request, $tenant);
            $page = $this->builds->paginate($tenant, (int) $request->integer('per_page', 20));

            return ApiResponse::success([
                'data' => collect($page->items())->map(fn ($build) => $this->builds->present($build))->values(),
                'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            ]);
        } catch (CustomerAppAuthorizationException $exception) {
            return $this->denied($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateBuildRequest($request);
        if ($input['build_type'] === 'app_bundle' && $input['platform'] !== 'android') {
            return ApiResponse::errors(null, 'App bundles are only available for Android.', 422, [
                'code' => 'INVALID_BUILD_COMBINATION',
            ]);
        }
        try {
            $tenant = $this->currentTenant($request);
            $result = $this->builds->request($request->user(), $tenant, $input);

            return $this->buildResponse($result);
        } catch (CustomerAppAuthorizationException $exception) {
            return $this->denied($exception);
        }
    }

    public function saasStore(Request $request, Tenant $tenant): JsonResponse
    {
        $input = $this->validateBuildRequest($request);
        if ($input['build_type'] === 'app_bundle' && $input['platform'] !== 'android') {
            return ApiResponse::errors(null, 'App bundles are only available for Android.', 422, ['code' => 'INVALID_BUILD_COMBINATION']);
        }

        try {
            return $this->buildResponse($this->builds->request($request->user(), $tenant, $input));
        } catch (CustomerAppAuthorizationException $exception) {
            return $this->denied($exception);
        }
    }

    public function saasIndex(Request $request, Tenant $tenant): JsonResponse
    {
        $page = $this->builds->paginate($tenant, (int) $request->integer('per_page', 20));

        return ApiResponse::success([
            'data' => collect($page->items())->map(fn ($build) => $this->builds->present($build))->values(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function saasDownload(Request $request, Tenant $tenant, string $uuid): StreamedResponse|JsonResponse
    {
        try {
            return $this->builds->download($request->user(), $tenant, $uuid);
        } catch (CustomerAppAuthorizationException $exception) {
            return $this->denied($exception);
        }
    }

    private function validateBuildRequest(Request $request): array
    {
        return $request->validate([
            'platform' => ['required', Rule::in(CustomerAppBuild::PLATFORMS)],
            'build_type' => ['required', Rule::in(CustomerAppBuild::TENANT_REQUESTABLE_BUILD_TYPES)],
            'requested_version' => ['required', 'string', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', 'max:50'],
            // Explicitly reject rather than silently trusting tenant spoofing fields.
            'tenant_id' => ['prohibited'], 'customer_app_registration_id' => ['prohibited'],
            'source_commit' => ['prohibited'], 'config_snapshot' => ['prohibited'],
        ]);
    }

    private function buildResponse(array $result): JsonResponse
    {
        return ApiResponse::success([
            'build' => $this->builds->present($result['build']),
            'reused' => $result['reused'],
        ], $result['reused'] ? 'Existing active build request returned.' : 'Build request safely queued.');
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        try {
            $tenant = $this->currentTenant($request);
            $this->assertAdministrator($request, $tenant);

            return ApiResponse::success(['build' => $this->builds->present($this->builds->find($tenant, $uuid))]);
        } catch (CustomerAppAuthorizationException $exception) {
            return $this->denied($exception);
        }
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        try {
            $tenant = $this->currentTenant($request);

            return ApiResponse::success(['build' => $this->builds->present($this->builds->cancel($request->user(), $tenant, $uuid))], 'Build request cancelled.');
        } catch (CustomerAppAuthorizationException $exception) {
            return $this->denied($exception);
        }
    }

    public function retry(Request $request, string $uuid): JsonResponse
    {
        try {
            $tenant = $this->currentTenant($request);
            $result = $this->builds->retry($request->user(), $tenant, $uuid);

            return ApiResponse::success(['build' => $this->builds->present($result['build']), 'reused' => $result['reused']], 'Build request queued again.');
        } catch (CustomerAppAuthorizationException $exception) {
            return $this->denied($exception);
        }
    }

    public function download(Request $request, string $uuid): StreamedResponse|JsonResponse
    {
        try {
            $tenant = $this->currentTenant($request);

            return $this->builds->download($request->user(), $tenant, $uuid);
        } catch (CustomerAppAuthorizationException $exception) {
            return $this->denied($exception);
        }
    }

    private function assertAdministrator(Request $request, $tenant): void
    {
        app(\Modules\Saas\Services\CustomerApp\CustomerAppAuthorizationService::class)
            ->assertBuildAccess($request->user(), $tenant);
    }

    private function denied(CustomerAppAuthorizationException $exception): JsonResponse
    {
        return ApiResponse::errors(null, $exception->getMessage(), $exception->httpStatus, ['code' => $exception->machineCode]);
    }
}
