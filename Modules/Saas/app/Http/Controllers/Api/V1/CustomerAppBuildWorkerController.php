<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppBuild;
use Modules\Saas\Services\CustomerApp\CustomerAppBuildWorkerService;
use Modules\Support\ApiResponse;

class CustomerAppBuildWorkerController extends Controller
{
    public function __construct(private readonly CustomerAppBuildWorkerService $worker) {}

    public function claim(Request $request): JsonResponse
    {
        try {
            $build = $this->worker->claim($this->workerId($request));

            return ApiResponse::success(['build' => $build ? $this->contract($build) : null]);
        } catch (CustomerAppAuthorizationException $exception) {
            return $this->denied($exception);
        }
    }

    public function heartbeat(Request $request, string $uuid): JsonResponse
    {
        return $this->run($request, $uuid, fn ($build, $workerId) => $this->worker->heartbeat($build, $workerId));
    }

    public function transition(Request $request, string $uuid): JsonResponse
    {
        $input = $request->validate([
            'status' => ['required', Rule::in(['building', 'testing', 'signing', 'verifying', 'uploading', 'cancelled'])],
            'metadata' => ['sometimes', 'array', 'max:12'],
        ]);

        return $this->run($request, $uuid, fn ($build, $workerId) => $this->worker->transition(
            $build,
            $workerId,
            $input['status'],
            (array) ($input['metadata'] ?? []),
        ));
    }

    public function fail(Request $request, string $uuid): JsonResponse
    {
        $input = $request->validate([
            'error_code' => ['required', 'string', 'regex:/\A[A-Z][A-Z0-9_]{2,63}\z/'],
            'message' => ['required', 'string', 'max:240'],
        ]);

        return $this->run($request, $uuid, fn ($build, $workerId) => $this->worker->fail(
            $build,
            $workerId,
            $input['error_code'],
            $input['message'],
        ));
    }

    public function complete(Request $request, string $uuid): JsonResponse
    {
        $maxKilobytes = max(1, (int) ceil(((int) config('saas.customer_app_build.max_artifact_bytes', 524288000)) / 1024));
        $input = $request->validate([
            'artifact' => ['required', 'file', "max:{$maxKilobytes}"],
            'checksum' => ['required', 'string', 'regex:/\A[0-9a-fA-F]{64}\z/'],
            'package_id' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,}\z/', 'max:255'],
            'commit' => ['required', 'string', 'regex:/\A[0-9a-fA-F]{40}\z/'],
            'signer_sha256' => ['required', 'string', 'regex:/\A[0-9a-fA-F]{64}\z/'],
            'version' => ['required', 'string', 'regex:/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?\z/', 'max:64'],
            'application_label' => ['required', 'string', 'max:80'],
            'flutter' => ['sometimes', 'string', 'max:80'],
            'dart' => ['sometimes', 'string', 'max:80'],
            'gradle' => ['sometimes', 'string', 'max:80'],
            'android_sdk' => ['sometimes', 'string', 'max:80'],
            'runner' => ['sometimes', 'string', 'max:120'],
            'duration_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
        ]);

        $artifact = $input['artifact'];
        unset($input['artifact']);

        return $this->run($request, $uuid, fn ($build, $workerId) => $this->worker->complete($build, $workerId, $artifact, $input));
    }

    private function run(Request $request, string $uuid, callable $operation): JsonResponse
    {
        try {
            $build = CustomerAppBuild::query()->withoutGlobalScopes()->where('uuid', $uuid)->first();
            if (! $build) {
                throw new CustomerAppAuthorizationException('BUILD_NOT_FOUND', 'The build request was not found.', 404);
            }

            return ApiResponse::success(['build' => $this->contract($operation($build, $this->workerId($request)))]);
        } catch (CustomerAppAuthorizationException $exception) {
            return $this->denied($exception);
        }
    }

    private function contract(CustomerAppBuild $build): array
    {
        $contract = [
            'uuid' => $build->uuid,
            'status' => $build->status,
            'platform' => $build->platform,
            'build_type' => $build->build_type,
            'requested_version' => $build->requested_version,
            'attempt' => (int) $build->attempt,
            'lease_expires_at' => $build->lease_expires_at?->toIso8601String(),
            'cancel_requested' => $build->status === 'cancel_requested',
            'config_snapshot' => $build->config_snapshot,
            'error_code' => $build->error_code,
            'error_message' => $build->error_message,
        ];
        if ($build->relationLoaded('artifact') && $build->artifact) {
            $contract['artifact'] = [
                'uuid' => $build->artifact->uuid,
                'checksum' => $build->artifact->checksum,
                'size_bytes' => (int) $build->artifact->size_bytes,
                'verified_at' => $build->artifact->verified_at?->toIso8601String(),
                'retention_expires_at' => $build->artifact->retention_expires_at?->toIso8601String(),
            ];
        }

        return $contract;
    }

    private function workerId(Request $request): string
    {
        return (string) $request->attributes->get('customer_app_build_worker_id');
    }

    private function denied(CustomerAppAuthorizationException $exception): JsonResponse
    {
        return ApiResponse::errors(null, $exception->getMessage(), $exception->httpStatus, ['code' => $exception->machineCode]);
    }
}
