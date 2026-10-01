<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Http\Requests\Api\V1\SaveUserRequest;
use Modules\User\Services\User\UserServiceInterface;
use Modules\User\Transformers\Api\V1\ShowUserResource;
use Modules\User\Transformers\Api\V1\UserResource;

class UserController extends Controller
{
    /**
     * Create a new instance of UserController
     *
     * @param UserServiceInterface $service
     */
    public function __construct(protected UserServiceInterface $service)
    {
    }

    /**
     * This method retrieves and returns a list of User models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::pagination(
            paginator: $this->service->get(
                filters: $request->get('filters', []),
                sorts: $request->get('sorts', []),
            ),
            resource: UserResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * This method retrieves and returns a single User model based on the provided identifier.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new ShowUserResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the User model.
     *
     * @param SaveUserRequest $request
     * @return JsonResponse
     */
    public function store(SaveUserRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new UserResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the User model.
     *
     * @param SaveUserRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveUserRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new UserResource($this->service->update($id, $request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method deletes the User model based on the provided ids.
     *
     * @param string $ids
     * @return JsonResponse
     */
    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label(),
            message: __('user::messages.users_cannot_be_destroyed')
        );
    }

    /**
     * Get form meta
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFormMeta(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->getFormMeta($request->get('branch_id')));
    }
}
