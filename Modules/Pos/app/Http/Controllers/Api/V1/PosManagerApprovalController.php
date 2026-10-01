<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Modules\Pos\Models\PosManagerApproval;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\Pos\Services\ManagerApproval\PosManagerApprovalService;
use Modules\Order\Models\Order;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class PosManagerApprovalController
{
    public function __construct(protected PosManagerApprovalService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->canApprovePosAction($request->user()), 403, __('pos::pos.manager_approval_not_allowed'));

        $validated = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'manager_id' => 'nullable|exists:users,id',
            'device_id' => 'nullable|string|max:120',
            'action' => 'nullable|string|max:120',
            'resource_type' => 'nullable|string|max:120',
            'resource_id' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:10|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        if (! empty($validated['branch_id'])) {
            abort_unless(
                $this->service->actorCanAccessBranch($request->user(), (int) $validated['branch_id']),
                403,
                __('pos::pos.manager_approval_not_allowed')
            );
        }

        if (! $this->service->storageReady()) {
            return response()->json([
                'data' => [
                    'data' => [],
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                    'storage_ready' => false,
                ],
            ]);
        }

        $approvals = PosManagerApproval::query()
            ->with('manager:id,name')
            ->when(! empty($validated['branch_id']), fn($query) => $query->where('branch_id', $validated['branch_id']))
            ->when(! empty($validated['manager_id']), fn($query) => $query->where('manager_id', $validated['manager_id']))
            ->when(! empty($validated['device_id']), fn($query) => $query->where('device_id', $validated['device_id']))
            ->when(! empty($validated['action']), fn($query) => $query->where('action', $validated['action']))
            ->when(! empty($validated['resource_type']), fn($query) => $query->where('resource_type', $validated['resource_type']))
            ->when(! empty($validated['resource_id']), fn($query) => $query->where('resource_id', $validated['resource_id']))
            ->latest()
            ->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => $approvals->through(fn(PosManagerApproval $approval) => $approval->toAuditPayload()),
            'managers' => $this->service->eligibleManagers(
                isset($validated['branch_id']) ? (int) $validated['branch_id'] : null
            )->values(),
        ]);
    }

    public function setPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:/^[0-9]{4,8}$/', 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();
        abort_unless($this->service->storageReady(), 503, __('pos::pos.manager_approval_storage_not_ready'));
        abort_unless($this->canApprovePosAction($user), 403, __('pos::pos.manager_approval_not_allowed'));

        $user->forceFill([
            'pos_pin_hash' => Hash::make($validated['pin']),
        ])->save();

        return response()->json([
            'message' => __('pos::pos.manager_pin_saved'),
        ]);
    }

    public function approve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'manager_id' => 'required|exists:users,id',
            'pin' => 'required|string|min:4|max:8',
            'branch_id' => 'required|exists:branches,id',
            'action' => ['required', 'string', 'max:120', Rule::in(['order.cancel', 'order.refund'])],
            'resource_type' => ['required', 'string', Rule::in(['order'])],
            'resource_id' => 'required|string|max:120',
            'reason' => 'nullable|string|max:500',
            'payload' => 'nullable|array',
            'expires_in_seconds' => 'nullable|integer|min:60|max:900',
        ]);

        abort_unless($this->service->storageReady(), 503, __('pos::pos.manager_approval_storage_not_ready'));
        abort_unless($this->service->actorCanAccessBranch($request->user(), (int) $validated['branch_id']), 403, __('pos::pos.manager_approval_not_allowed'));

        Order::query()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', (int) $validated['branch_id'])
            ->whereKey($validated['resource_id'])
            ->firstOrFail();

        $manager = User::query()
            ->withOutGlobalBranchPermission()
            ->with('roles')
            ->findOrFail($validated['manager_id']);

        abort_unless($this->service->isSupportedAction($validated['action']), 422, __('pos::pos.manager_approval_invalid'));
        abort_unless($this->service->canApproveAtBranch($manager, (int) $validated['branch_id'], $request->user()), 403, __('pos::pos.manager_approval_not_allowed'));
        abort_unless($this->canApprovePosAction($manager), 403, __('pos::pos.manager_approval_not_allowed'));
        abort_if(empty($manager->pos_pin_hash), 422, __('pos::pos.manager_pin_not_configured'));
        abort_unless(Hash::check($validated['pin'], $manager->pos_pin_hash), 422, __('pos::pos.manager_pin_invalid'));

        $deviceId = mb_substr((string) $request->header('X-NexDine-Device-Id'), 0, 120) ?: null;
        $terminalDevice = $deviceId
            ? PosTerminalDevice::query()->where('device_id', $deviceId)->first()
            : null;
        $expiresInSeconds = (int) ($validated['expires_in_seconds'] ?? 300);

        $approval = PosManagerApproval::query()->create([
            'branch_id' => $validated['branch_id'],
            'manager_id' => $manager->id,
            'pos_terminal_device_id' => $terminalDevice?->id,
            'device_id' => $deviceId,
            'action' => $validated['action'],
            'resource_type' => $validated['resource_type'],
            'resource_id' => $validated['resource_id'],
            'approval_token' => (string) Str::uuid(),
            'status' => 'approved',
            'reason' => $validated['reason'] ?? null,
            'payload' => $validated['payload'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'approved_at' => now(),
            'expires_at' => now()->addSeconds($expiresInSeconds),
        ]);

        activity('pos_manager_approvals')
            ->event('approved')
            ->causedBy(auth()->user())
            ->performedOn($approval)
            ->withProperties($approval->toAuditPayload())
            ->log("POS manager approval recorded");

        return response()->json([
            'data' => [
                'approval_token' => $approval->approval_token,
                'expires_at' => $approval->expires_at?->toISOString(),
                'approval' => $approval->toAuditPayload(),
            ],
        ]);
    }

    private function canApprovePosAction(User $user): bool
    {
        return $user->hasAnyRole([
            DefaultRole::SuperAdmin->value,
            DefaultRole::Admin->value,
            DefaultRole::EnterpriseAdmin->value,
            DefaultRole::AdminBranch->value,
            DefaultRole::Manager->value,
        ]) || $user->can('admin.orders.cancel');
    }
}
