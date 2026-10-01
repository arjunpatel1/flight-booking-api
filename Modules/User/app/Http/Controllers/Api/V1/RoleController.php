<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Http\Requests\Api\V1\SaveRoleRequest;
use Modules\User\Services\Role\RoleServiceInterface;
use Modules\User\Transformers\Api\V1\RoleResource;
use Modules\User\Transformers\Api\V1\RoleShowResource;
use Modules\User\Support\RolePermissionBoundary;

class RoleController extends Controller
{
    /**
     * Create a new instance of RoleController
     *
     * @param RoleServiceInterface $service
     */
    public function __construct(protected RoleServiceInterface $service)
    {
    }

    /**
     * Display a listing of the roles.
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
            resource: RoleResource::class,
            filters: $request->get('with_filters')
                ? $this->service->getStructureFilters()
                : null
        );
    }

    /**
     * Show the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(
            body: new RoleShowResource($this->service->show($id))
        );
    }

    /**
     * Store a newly created role in storage.
     *
     * @param SaveRoleRequest $request
     * @return JsonResponse
     */
    public function store(SaveRoleRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new RoleShowResource(
                $this->service->store($request->validated())
            ),
            resource: $this->service->label()
        );
    }

    /**
     * Update the specified role in storage.
     *
     * @param SaveRoleRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveRoleRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new RoleShowResource(
                $this->service->update($id, $request->validated())
            ),
            resource: $this->service->label()
        );
    }

    /**
     * Destroy resources by given ids.
     *
     * @param string $ids
     * @return JsonResponse
     */
    public function destroy(string $ids): JsonResponse
    {
        return ApiResponse::destroyed(
            destroyed: $this->service->destroy($ids),
            resource: $this->service->label(),
            message: __('user::messages.roles_cannot_be_destroyed')
        );
    }

    /**
     * Get form meta
     *
     * @return JsonResponse
     */
    public function getFormMeta(): JsonResponse
    {
        return ApiResponse::success($this->service->getFormMeta());
    }

    /**
     * Sync permissions for a role.
     */
    public function syncPermissions(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', \Illuminate\Validation\Rule::in(
                app(RolePermissionBoundary::class)->allowedNames($request->user())
            )],
        ]);

        return ApiResponse::updated(
            body: new RoleShowResource($this->service->syncPermissions($id, $data['permissions'])),
            resource: $this->service->label()
        );
    }

    /**
     * Get permissions tree.
     */
    public function permissionTree(): JsonResponse
    {
        return ApiResponse::success($this->service->getPermissionTree());
    }
}
