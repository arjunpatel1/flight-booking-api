<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Http\Requests\Api\V1\QuickStoreCustomerRequest;
use Modules\User\Http\Requests\Api\V1\SaveCustomerRequest;
use Modules\User\Services\Customer\CustomerServiceInterface;
use Modules\User\Transformers\Api\V1\UserResource;

class CustomerController extends Controller
{
    /**
     * Create a new instance of UserController
     *
     * @param CustomerServiceInterface $service
     */
    public function __construct(protected CustomerServiceInterface $service)
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
     * Get CRM engagement summary.
     */
    public function engagementSummary(): JsonResponse
    {
        return ApiResponse::success(
            body: $this->service->engagementSummary()
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
            body: new UserResource($this->service->show($id))
        );
    }

    /**
     * This method stores the provided data into storage for the User model.
     *
     * @param SaveCustomerRequest $request
     * @return JsonResponse
     */
    public function store(SaveCustomerRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new UserResource($this->service->store($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * This method updates the provided data for the User model.
     *
     * @param SaveCustomerRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(SaveCustomerRequest $request, int $id): JsonResponse
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
     * Quick store a customer from POS screen.
     */
    public function quickStore(QuickStoreCustomerRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new UserResource($this->service->quickStore($request->validated())),
            resource: $this->service->label()
        );
    }

    /**
     * Show detailed customer with orders, loyalty, gift cards.
     */
    public function showDetail(int $id): JsonResponse
    {
        return ApiResponse::success($this->service->showDetail($id));
    }
}
