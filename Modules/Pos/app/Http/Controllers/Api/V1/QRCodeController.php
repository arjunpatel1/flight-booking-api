<?php

namespace Modules\Pos\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Menu\Models\OnlineMenu;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Pos\Http\Requests\Api\V1\CallWaiterRequest;
use Modules\Pos\Services\QRCode\QRCodeServiceInterface;
use Modules\SeatingPlan\Models\Table;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class QRCodeController extends Controller
{
    public function __construct(
        private readonly NotificationServiceInterface $notifications,
        private readonly QRCodeServiceInterface $qrCodes,
    )
    {
    }

    public function resolve(Request $request): JsonResponse
    {
        $this->guardCustomerTableQr();

        $data = $request->validate(['payload' => ['required', 'string', 'max:8192']]);
        $decoded = $this->qrCodes->validateQRCode($data['payload']);
        abort_unless(($decoded['valid'] ?? false) === true
            && filled($decoded['table_id'] ?? null)
            && filled($decoded['branch_id'] ?? null), 422, $decoded['error'] ?? 'This is not a valid table QR code.');

        $table = Table::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', PublicTenantGuard::tenantId($request)))
            ->where('branch_id', (int) ($decoded['branch_id'] ?? 0))
            ->where('is_active', true)
            ->findOrFail((int) ($decoded['table_id'] ?? 0));

        $menuReferences = OnlineMenu::query()
            ->withOutGlobalBranchPermission()
            ->with('menu:id,uuid')
            ->where('branch_id', $table->branch_id)
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', PublicTenantGuard::tenantId($request)))
            ->get()
            ->pluck('menu.uuid')
            ->filter()
            ->unique()
            ->values();

        return ApiResponse::success(body: [
            'table_name' => $table->name,
            'table_token' => $table->uuid,
            'menu_references' => $menuReferences,
        ]);
    }

    public function callWaiter(CallWaiterRequest $request): JsonResponse
    {
        $this->guardCustomerTableQr();

        $data = $request->validated();
        $table = $this->resolveTable($request, $data['table_token'] ?? null, $data['table_number'] ?? null, $data['menu_slug'] ?? null);
        abort_if(filled($data['table_token'] ?? null) && ! $table, 422, 'This table QR credential is invalid.');
        $branchId = $table?->branch_id ?? $this->resolveBranchId($request, $data['menu_slug'] ?? null);
        abort_if(blank($branchId), 422, __('validation.required', ['attribute' => 'branch_id']));

        $type = $data['type'];

        $payload = [
            'table_id' => $table?->id,
            'table_name' => $table?->name,
            'room_number' => $data['room_number'] ?? $data['table_number'] ?? null,
            'menu_slug' => $data['menu_slug'] ?? null,
            'type' => $type,
            'notes' => $data['notes'] ?? null,
            'branch_id' => $branchId,
        ];

        $title = __('pos::qr_order.waiter_request_title');
        $message = __('pos::qr_order.waiter_request_message', [
            'type' => __("pos::qr_order.request_types.$type"),
            'table' => $table?->name ?? ($data['room_number'] ?? __('pos::qr_order.unknown_table')),
        ]);

        $recipients = $this->recipients($branchId, PublicTenantGuard::tenantId($request));
        // Never create a target-less notification: target-less notifications
        // are visible to every logged-in user and would leak a guest request
        // across restaurants. The caller receives a clear retryable error if
        // this branch has no active staff recipient.
        abort_if($recipients->isEmpty(), 409, 'No active restaurant staff are available for this request.');

        foreach ($recipients as $recipient) {
            $this->notifications->create([
                'title' => $title,
                'message' => $message,
                'type' => 'waiter_request',
                'severity' => NotificationSeverity::Warning->value,
                'icon' => 'tabler-bell-ringing',
                'action_url' => '/admin/restaurant-map',
                'payload' => $payload,
            ], $recipient);
        }

        return ApiResponse::success(
            body: ['success' => true],
            message: __('pos::qr_order.waiter_notified')
        );
    }

    private function guardCustomerTableQr(): void
    {
        abort_unless((bool) setting('customer_app_enabled', true), 403, 'Customer ordering is not available for this restaurant.');
    }

    private function resolveTable(Request $request, ?string $tableToken, ?string $tableNumber = null, ?string $menuSlug = null): ?Table
    {
        $query = Table::query()
            ->withOutGlobalBranchPermission()
            ->withoutGlobalActive()
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', PublicTenantGuard::tenantId($request)))
            ->where('is_active', true);

        if (filled($tableToken)) {
            return $query->where('uuid', $tableToken)->first();
        }

        if (blank($tableNumber)) return null;

        $branchId = $this->resolveBranchId($request, $menuSlug);
        if (blank($branchId)) return null;

        $label = mb_strtolower(trim($tableNumber));
        return $query->where('branch_id', $branchId)->get()
            ->first(fn (Table $table) => mb_strtolower(trim((string) $table->name)) === $label);
    }

    private function resolveBranchId(Request $request, ?string $slug): ?int
    {
        if (blank($slug)) {
            return null;
        }

        return OnlineMenu::query()
            ->withOutGlobalBranchPermission()
            ->whereHas('branch', fn ($query) => $query->where('tenant_id', PublicTenantGuard::tenantId($request)))
            ->where('slug', $slug)
            ->value('branch_id');
    }

    private function recipients(?int $branchId, int $tenantId)
    {
        return User::query()
            ->select(['id', 'name', 'branch_id'])
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenantId)
            ->when(!is_null($branchId), function ($query) use ($branchId) {
                $query->where(fn($branchQuery) => $branchQuery
                    ->where('branch_id', $branchId)
                    ->orWhereNull('branch_id'));
            })
            ->role([
                DefaultRole::SuperAdmin->value,
                DefaultRole::Admin->value,
                DefaultRole::AdminBranch->value,
                DefaultRole::Manager->value,
                DefaultRole::Cashier->value,
                DefaultRole::Waiter->value,
            ])
            ->get();
    }
}
