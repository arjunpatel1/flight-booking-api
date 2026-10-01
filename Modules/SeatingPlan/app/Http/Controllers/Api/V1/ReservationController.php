<?php

namespace Modules\SeatingPlan\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\SeatingPlan\Http\Requests\Api\V1\CancelReservationRequest;
use Modules\SeatingPlan\Http\Requests\Api\V1\SaveReservationRequest;
use Modules\SeatingPlan\Services\Reservation\ReservationServiceInterface;
use Modules\SeatingPlan\Transformers\Api\V1\ReservationResource;
use Modules\Support\ApiResponse;

class ReservationController extends Controller
{
    public function __construct(protected ReservationServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ReservationResource::collection($this->service->get($request->all()))
        );
    }

    public function upcoming(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ReservationResource::collection($this->service->upcoming($request->integer('branch_id') ?: null))
        );
    }

    public function byTable(Request $request, int $tableId): JsonResponse
    {
        return ApiResponse::success(
            ReservationResource::collection($this->service->byTable($tableId, $request->get('date')))
        );
    }

    public function store(SaveReservationRequest $request): JsonResponse
    {
        return ApiResponse::created(
            body: new ReservationResource($this->service->store($request->validated())),
            resource: __('seatingplan::reservations.reservation')
        );
    }

    public function update(SaveReservationRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new ReservationResource($this->service->update($id, $request->validated())),
            resource: __('seatingplan::reservations.reservation')
        );
    }

    public function confirm(int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new ReservationResource($this->service->confirm($id)),
            resource: __('seatingplan::reservations.reservation')
        );
    }

    public function seat(int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new ReservationResource($this->service->seat($id)),
            resource: __('seatingplan::reservations.reservation')
        );
    }

    public function cancel(CancelReservationRequest $request, int $id): JsonResponse
    {
        return ApiResponse::updated(
            body: new ReservationResource($this->service->cancel($id, $request->get('reason'))),
            resource: __('seatingplan::reservations.reservation')
        );
    }

    public function meta(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->meta($request->integer('branch_id') ?: null)
        );
    }
}
