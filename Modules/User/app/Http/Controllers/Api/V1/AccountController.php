<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Http\Requests\Api\V1\UpdatePasswordRequest;
use Modules\User\Http\Requests\Api\V1\UpdateProfileRequest;
use Modules\User\Http\Requests\Api\V1\ConfirmMfaRequest;
use Modules\User\Services\Account\AccountServiceInterface;
use Modules\User\Transformers\Api\V1\AuthResource;
use Modules\User\Transformers\Api\V1\MeResource;

class AccountController extends Controller
{
    /**
     * Create a new instance of AccountController
     *
     * @param AccountServiceInterface $service
     */
    public function __construct(protected AccountServiceInterface $service)
    {
    }

    /**
     * Get user information
     *
     * @return JsonResponse
     */
    public function me(): JsonResponse
    {
        return ApiResponse::success(new MeResource($this->service->me()));
    }

    /**
     * Update the user's profile.
     *
     * @param UpdateProfileRequest $request
     * @return JsonResponse
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        return ApiResponse::updated(
            body: ["user" => new AuthResource($this->service->updateProfile($request->validated()))],
            resource: __("user::profiles.profile")
        );
    }

    /**
     * Update the user's password.
     *
     * @param UpdatePasswordRequest $request
     * @return JsonResponse
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        return ApiResponse::updated(
            body: ["success" => $this->service->updatePassword($request->validated())],
            resource: __("user::profiles.password")
        );
    }

    public function beginMfaSetup(): JsonResponse
    {
        return ApiResponse::success($this->service->beginMfaSetup());
    }

    public function confirmMfaSetup(ConfirmMfaRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->confirmMfaSetup($request->validated('code')));
    }

    public function disableMfa(): JsonResponse
    {
        return ApiResponse::success(['success' => $this->service->disableMfa()]);
    }
}
