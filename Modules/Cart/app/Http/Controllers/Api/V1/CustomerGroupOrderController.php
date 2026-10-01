<?php

namespace Modules\Cart\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Validation\Rule;
use Modules\Cart\Services\CustomerGroupOrderService;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Enums\OrderType;
use Modules\Support\ApiResponse;
use Modules\Support\InputLimit;

class CustomerGroupOrderController extends Controller
{
    public function __construct(private readonly CustomerGroupOrderService $groups) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'menu_reference' => ['required_without:branch_id', 'nullable', 'uuid'],
            'branch_id' => ['required_without:menu_reference', 'nullable', 'integer'],
            'order_type' => ['nullable', Rule::enum(OrderType::class)],
            'participant_limit' => ['nullable', 'integer', 'between:2,20'],
            'expires_in_minutes' => ['nullable', 'integer', 'between:15,240'],
        ]);

        return ApiResponse::success($this->groups->create($request, $data), code: 201);
    }

    public function show(Request $request, string $groupId): JsonResponse
    {
        return ApiResponse::success($this->groups->show($request, $groupId));
    }

    public function broadcastingAuth(Request $request): JsonResponse
    {
        $request->validate([
            'socket_id' => ['required', 'string', 'max:100'],
            'channel_name' => ['required', 'string', 'max:255'],
        ]);

        return Broadcast::auth($request);
    }

    public function resolveInvite(Request $request, string $token): JsonResponse
    {
        return ApiResponse::success($this->groups->resolveInvite($request, $token));
    }

    public function join(Request $request, string $token): JsonResponse
    {
        return ApiResponse::success($this->groups->join($request, $token));
    }

    public function addItem(Request $request, string $groupId): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'product_reference' => ['required_without:product_id', 'nullable', 'uuid'],
            'product_id' => ['required_without:product_reference', 'nullable', 'integer'],
            'quantity' => ['required', ...InputLimit::quantity(min: 1, integer: true)],
            'options' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        return ApiResponse::success($this->groups->addItem($request, $groupId, $data));
    }

    public function updateItem(Request $request, string $groupId, string $itemId): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', ...InputLimit::quantity(min: 1, integer: true)],
            'options' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        return ApiResponse::success($this->groups->updateItem($request, $groupId, $itemId, $data));
    }

    public function removeItem(Request $request, string $groupId, string $itemId): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ApiResponse::success($this->groups->removeItem($request, $groupId, $itemId, (int) $data['version']));
    }

    public function lock(Request $request, string $groupId): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ApiResponse::success($this->groups->lock($request, $groupId, $data));
    }

    public function removeParticipant(Request $request, string $groupId, string $participantReference): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ApiResponse::success($this->groups->removeParticipant($request, $groupId, $participantReference, (int) $data['version']));
    }

    public function cancel(Request $request, string $groupId): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ApiResponse::success($this->groups->cancel($request, $groupId, (int) $data['version']));
    }

    public function prepareCheckout(Request $request, string $groupId): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ApiResponse::success($this->groups->prepareCheckout($request, $groupId, (int) $data['version']));
    }

    public function applyCoupon(Request $request, string $groupId): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:80']]);

        return ApiResponse::success($this->groups->applyCoupon($request, $groupId, (int) $data['version'], $data['code']));
    }

    public function removeCoupon(Request $request, string $groupId): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ApiResponse::success($this->groups->removeCoupon($request, $groupId, (int) $data['version']));
    }

    public function complete(Request $request, string $groupId): JsonResponse
    {
        $data = $request->validate(['order_reference' => ['required', 'string', 'max:100']]);

        return ApiResponse::success($this->groups->complete($request, $groupId, $data['order_reference']));
    }
}
