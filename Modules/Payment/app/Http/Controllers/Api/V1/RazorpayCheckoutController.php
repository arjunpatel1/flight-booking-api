<?php

namespace Modules\Payment\Http\Controllers\Api\V1;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Cart\Support\PublicTenantGuard;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Order\Delivery\DeliveryPaymentPolicy;
use Modules\Order\Delivery\DeliveryWallet;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Http\Controllers\Api\V1\DeliveryWalletFundingController;
use Modules\Order\Models\Order;
use Modules\Payment\Models\TenantPaymentGatewayConfig;
use Modules\Payment\Models\TenantPaymentSession;
use Modules\Payment\Services\RazorpayCheckoutService;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class RazorpayCheckoutController extends Controller
{
    public function __construct(
        private readonly NotificationServiceInterface $notifications,
        private readonly EffectiveTenantEntitlementService $entitlements,
    ) {}

    public function partnerWebhook(Request $request, RazorpayCheckoutService $service): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Razorpay-Signature');
        $secret = (string) config('payment.gateways.razorpay.webhook_secret');
        abort_unless($secret !== '' && $signature !== ''
            && hash_equals(hash_hmac('sha256', $raw, $secret), $signature), 401,
            'Invalid Razorpay webhook signature.');
        $payload = json_decode($raw, true);
        abort_unless(is_array($payload), 422, 'Invalid webhook payload.');
        $entity = data_get($payload, 'payload.payment.entity');
        if (($payload['event'] ?? null) === 'payment.captured' && is_array($entity)
            && data_get($entity, 'notes.purpose') === 'delivery_wallet_top_up') {
            app(DeliveryWalletFundingController::class)->processGatewayWebhook($entity, app(DeliveryWallet::class));
        } else {
            $service->processPartnerWebhook($payload);
        }

        return response()->json(['accepted' => true]);
    }

    public function options(Request $request): JsonResponse
    {
        $tenantId = PublicTenantGuard::tenantId($request);
        $data = $request->validate([
            'menu_reference' => ['nullable', 'uuid', 'required_without_all:order_reference,branch_id'],
            'order_reference' => ['nullable', 'string', 'regex:/^ORD-[A-Z0-9]{20}$/', 'required_without_all:menu_reference,branch_id'],
            // Backward-compatible fallback for clients deployed before opaque references.
            'branch_id' => ['nullable', 'integer', 'required_without_all:menu_reference,order_reference'],
        ]);

        if (filled($data['menu_reference'] ?? null)) {
            $branch = PublicTenantGuard::branch(
                $request,
                PublicTenantGuard::menu($request, $data['menu_reference'])->branch_id,
            );
        } elseif (filled($data['order_reference'] ?? null)) {
            // This endpoint is deliberately usable by anonymous menu checkout,
            // so auth middleware cannot be required globally. Resolve a bearer
            // token only for the existing-order branch and keep the order
            // tenant/customer scoped below.
            $customer = $request->user() ?: Auth::guard('sanctum')->user();
            abort_unless($customer && (int) $customer->tenant_id === $tenantId, 401, 'Sign in to view payment options for an existing order.');
            $order = Order::query()
                ->withOutGlobalBranchPermission()
                ->where('reference_no', $data['order_reference'])
                ->where('customer_id', (int) $customer->id)
                ->whereIn('branch_id', \DB::table('branches')->select('id')->where('tenant_id', $tenantId))
                ->firstOrFail();
            $branch = PublicTenantGuard::branch($request, $order->branch_id);
        } else {
            $branch = PublicTenantGuard::branch($request, (int) $data['branch_id']);
        }

        $branchId = (int) $branch->id;
        $tenant = Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->where('is_active', true)->first();
        $onlinePaymentsEnabled = $tenant && $this->entitlements->has($tenant, 'payments');
        $branchPaymentMethods = collect($branch->payment_methods ?? [])
            ->map(fn ($method) => strtolower((string) (is_array($method) ? ($method['value'] ?? $method['id'] ?? '') : $method)))
            ->filter();
        $cashEnabledForBranch = $branchPaymentMethods->isEmpty() || $branchPaymentMethods->contains('cash');
        $razorpayEnabledForBranch = $branchPaymentMethods->isEmpty() || $branchPaymentMethods->intersect(['razorpay', 'upi', 'card', 'mobile_wallet'])->isNotEmpty();
        $razorpay = TenantPaymentGatewayConfig::query()->where('tenant_id', $tenantId)
            ->where('provider', 'razorpay')->where('enabled', true)->first();
        $razorpayForBranch = $razorpay && ($razorpay->branches()->count() === 0 || $razorpay->branches()->whereKey($branchId)->exists());

        return ApiResponse::success([
            'cash_on_delivery' => $cashEnabledForBranch && (bool) setting('customer_payment_cod_enabled', false),
            'pay_at_counter' => $cashEnabledForBranch && (bool) setting('customer_payment_counter_enabled', true),
            // Counter/COD discovery is part of ordering and must remain
            // available without the online-payments add-on. Only expose the
            // gateway when the tenant can actually create a payment session.
            'razorpay' => $onlinePaymentsEnabled && $razorpayEnabledForBranch
                && (bool) setting('customer_payment_razorpay_enabled', false) && $razorpayForBranch,
            'wallet' => $onlinePaymentsEnabled,
            // Delivery-specific availability: a third-party partner may only
            // accept some payment types, so delivery checkout uses these.
            'delivery_razorpay' => $onlinePaymentsEnabled && $razorpayEnabledForBranch
                && (bool) setting('customer_payment_razorpay_enabled', false) && $razorpayForBranch
                && DeliveryPaymentPolicy::allowsOnlinePayment(),
            'delivery_cash_on_delivery' => $cashEnabledForBranch && DeliveryPaymentPolicy::allowsCashOnDelivery(),
        ]);
    }

    public function create(Request $request, RazorpayCheckoutService $service): JsonResponse
    {
        $data = $request->validate(['order_reference' => ['required', 'string', 'regex:/^ORD-[A-Z0-9]{20}$/']]);
        $tenantId = PublicTenantGuard::tenantId($request);
        $customerId = (int) $request->user()->id;
        $order = Order::query()->withOutGlobalBranchPermission()->where('reference_no', $data['order_reference'])
            ->where('customer_id', $customerId)
            ->whereIn('branch_id', \DB::table('branches')->select('id')->where('tenant_id', $tenantId))->firstOrFail();
        $key = trim((string) $request->header('Idempotency-Key'));
        abort_unless(strlen($key) >= 16 && strlen($key) <= 120, 422, 'A valid Idempotency-Key header is required.');
        try {
            $session = $service->create($tenantId, $customerId, $order->id, $key);
        } catch (UniqueConstraintViolationException) {
            $session = TenantPaymentSession::query()->where('tenant_id', $tenantId)->where('provider', 'razorpay')->where('idempotency_key', $key)->firstOrFail();
        }

        return ApiResponse::success($service->present($session));
    }

    public function requestManualPayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_reference' => ['required', 'string', 'regex:/^ORD-[A-Z0-9]{20}$/'],
            'note' => ['nullable', 'string', 'max:240'],
        ]);
        $tenantId = PublicTenantGuard::tenantId($request);
        $customerId = (int) $request->user()->id;
        $order = Order::query()->withOutGlobalBranchPermission()
            ->with(['branch:id,name'])
            ->where('reference_no', $data['order_reference'])
            ->where('customer_id', $customerId)
            ->whereIn('branch_id', \DB::table('branches')->select('id')->where('tenant_id', $tenantId))
            ->firstOrFail();

        abort_unless($order->payment_status?->isUnpaid(), 409, 'This order no longer has an outstanding payment.');
        abort_if(in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Refunded, OrderStatus::Completed, OrderStatus::Served], true), 409, 'Manual payment cannot be requested for this order.');

        $tenantAdminRoles = [DefaultRole::Admin->value, DefaultRole::EnterpriseAdmin->value];
        $branchStaffRoles = [DefaultRole::AdminBranch->value, DefaultRole::Manager->value, DefaultRole::Cashier->value, DefaultRole::Waiter->value];
        $recipients = User::query()->select(['id', 'name', 'branch_id'])
            ->where('tenant_id', $tenantId)->where('is_active', 1)->whereNull('deleted_at')
            ->where(function ($query) use ($order, $tenantAdminRoles, $branchStaffRoles) {
                // Tenant administrators oversee every branch. Operational staff
                // receive only requests for their assigned branch (or all
                // branches when their account is intentionally unassigned).
                $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', $tenantAdminRoles))
                    ->orWhere(function ($branchQuery) use ($order, $branchStaffRoles) {
                        $branchQuery->where(fn ($scope) => $scope->where('branch_id', $order->branch_id)->orWhereNull('branch_id'))
                            ->whereHas('roles', fn ($roles) => $roles->whereIn('name', $branchStaffRoles));
                    })->orWhere(function ($permissionQuery) use ($order) {
                        // Custom tenant roles are valid operational recipients
                        // when they can actually collect an order payment.
                        $permissionQuery->where(fn ($scope) => $scope->where('branch_id', $order->branch_id)->orWhereNull('branch_id'))
                            ->whereHas('roles.permissions', fn ($permissions) => $permissions
                                ->where('name', 'admin.orders.receive_payment'));
                    });
            })
            ->get();
        abort_if($recipients->isEmpty(), 409, 'No active restaurant staff are available for this payment request.');

        foreach ($recipients as $recipient) {
            $this->notifications->create([
                'title' => "Payment collection requested · Order #{$order->order_number}",
                'message' => trim("Customer requested staff assistance to collect the outstanding payment for {$order->branch?->name}. ".($data['note'] ?? '')),
                'type' => 'customer_payment_request',
                'severity' => NotificationSeverity::Warning->value,
                'icon' => 'tabler-cash-register',
                'action_url' => "/admin/orders/{$order->id}/show",
                'payload' => ['order_reference' => $order->reference_no, 'order_number' => $order->order_number, 'branch_id' => $order->branch_id, 'customer_id' => $customerId, 'notification_kind' => 'manual_payment_request'],
            ], $recipient);
        }

        return ApiResponse::success([
            'requested_at' => now()->toISOString(),
            'recipients' => $recipients->count(),
        ], 'Restaurant staff have been notified to collect your payment.');
    }

    public function verify(Request $request, string $reference, RazorpayCheckoutService $service): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string', 'max:255'],
            'razorpay_order_id' => ['required', 'string', 'max:255'],
            'razorpay_signature' => ['required', 'string', 'size:64'],
        ]);
        $session = TenantPaymentSession::query()->where('tenant_id', PublicTenantGuard::tenantId($request))
            ->where('created_by', (int) $request->user()->id)->where('provider', 'razorpay')->where('reference', $reference)->firstOrFail();

        return ApiResponse::success($service->verify($session, $data));
    }
}
