<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Services\NotificationServiceInterface;
use Modules\Saas\Models\SaasBillingInvoice;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Saas\Models\TenantPlanUpgradeRequest;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Saas\Traits\ResolvesCurrentTenant;
use Modules\Support\ApiResponse;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;

class TenantBillingController extends Controller
{
    use ResolvesCurrentTenant;

    public function __construct(
        private readonly EffectiveTenantEntitlementService $entitlements,
        private readonly NotificationServiceInterface $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        $effectiveFeatures = $this->entitlements->features($tenant);
        $subscription = $this->entitlements->subscription($tenant);
        $expiry = $subscription?->status === 'trial' ? ($subscription->trial_ends_at ?? $subscription->ends_at) : $subscription?->ends_at;
        $invoices = SaasBillingInvoice::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->latest('issued_at')->limit(20)->get();
        $requests = TenantPlanUpgradeRequest::query()->where('tenant_id', $tenant->id)->with('requestedPlan:id,name')->latest()->limit(20)->get();

        return ApiResponse::success([
            'services' => \Modules\Saas\Models\TenantServiceAssignment::query()->where('tenant_id', $tenant->id)->with('invoice')->latest()->limit(100)->get()->map(fn ($s) => [
                'uuid'=>$s->uuid, 'feature'=>$s->feature, 'billing_mode'=>$s->billing_mode,
                'status'=>$s->status, 'access_active'=>$s->reportsAccess($tenant, $effectiveFeatures), 'starts_at'=>$s->starts_at,
                'ends_at'=>$s->ends_at, 'invoice_number'=>$s->invoice?->invoice_number,
            ]),
            'subscription' => $subscription ? ['id' => $subscription->id, 'status' => $subscription->status, 'starts_at' => $subscription->starts_at?->toIso8601String(), 'ends_at' => $subscription->ends_at?->toIso8601String(), 'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(), 'days_remaining' => $expiry ? max(0, (int) now()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false)) : null, 'plan' => $subscription->plan?->only(['id', 'name', 'code', 'price', 'currency', 'billing_cycle'])] : null,
            'features' => $effectiveFeatures,
            'plans' => SubscriptionPlan::query()->where('is_active', true)->orderBy('price')->get(['id', 'name', 'code', 'price', 'currency', 'billing_cycle']),
            'invoices' => $invoices->map(fn ($invoice) => $invoice->only(['id', 'invoice_number', 'amount', 'currency', 'status', 'gateway', 'gateway_reference', 'issued_at', 'due_at', 'paid_at', 'payment_url'])),
            'upgrade_requests' => $requests->map(fn ($item) => ['uuid' => $item->uuid, 'status' => $item->status, 'requested_plan' => $item->requestedPlan?->name, 'reason' => $item->reason, 'notes' => $item->notes, 'decision_note' => $item->decision_note, 'created_at' => $item->created_at?->toIso8601String()]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        $data = $request->validate(['requested_plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where('is_active', true)], 'reason' => ['required', 'string', 'min:5', 'max:255'], 'contact_name' => ['required', 'string', 'max:120'], 'contact_email' => ['required', 'email', 'max:160'], 'contact_phone' => ['nullable', 'string', 'max:30'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $upgrade = DB::transaction(function () use ($tenant, $request, $data) {
            $tenant = $tenant->newQueryWithoutScopes()->lockForUpdate()->findOrFail($tenant->id);
            abort_if(TenantPlanUpgradeRequest::query()->where('tenant_id', $tenant->id)->where('requested_plan_id', $data['requested_plan_id'])->where('status', 'pending')->exists(), 409, 'An upgrade request for this plan is already pending.');
            $current = $this->entitlements->subscription($tenant);
            $item = TenantPlanUpgradeRequest::query()->create([...$data, 'tenant_id' => $tenant->id, 'current_plan_id' => $current?->subscription_plan_id, 'requested_by' => $request->user()->id, 'status' => 'pending']);
            DB::table('tenant_plan_upgrade_request_events')->insert(['tenant_plan_upgrade_request_id' => $item->id, 'actor_id' => $request->user()->id, 'to_status' => 'pending', 'note' => $data['reason'], 'created_at' => now()]);
            return $item;
        });
        activity('subscription')->performedOn($upgrade)->causedBy($request->user())->withProperties(['tenant_id' => $tenant->id])->log('Plan upgrade requested');
        $this->notifyAdmin($upgrade);
        return ApiResponse::created(['uuid' => $upgrade->uuid, 'status' => $upgrade->status], 'Upgrade request sent.');
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $tenant = $this->currentTenant($request);
        $item = TenantPlanUpgradeRequest::query()->where('tenant_id', $tenant->id)->where('uuid', $uuid)->where('status', 'pending')->firstOrFail();
        DB::transaction(function () use ($item, $request) { $item->update(['status' => 'cancelled', 'decided_at' => now(), 'decided_by' => $request->user()->id]); DB::table('tenant_plan_upgrade_request_events')->insert(['tenant_plan_upgrade_request_id' => $item->id, 'actor_id' => $request->user()->id, 'from_status' => 'pending', 'to_status' => 'cancelled', 'created_at' => now()]); });
        return ApiResponse::success(['uuid' => $item->uuid, 'status' => 'cancelled'], 'Upgrade request cancelled.');
    }

    public function invoice(Request $request, int $invoice): Response
    {
        $tenant = $this->currentTenant($request);
        $row = SaasBillingInvoice::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($invoice)->firstOrFail();
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Invoice '.e($row->invoice_number).'</title><style>body{font:15px system-ui;max-width:760px;margin:40px auto;color:#172033}h1{color:#f57c00}dl{display:grid;grid-template-columns:180px 1fr;gap:12px}dt{color:#667085}dd{margin:0;font-weight:600}</style></head><body><h1>NexDine Invoice</h1><dl><dt>Restaurant</dt><dd>'.e($tenant->name).'</dd><dt>Invoice</dt><dd>'.e($row->invoice_number).'</dd><dt>Amount</dt><dd>'.e($row->currency).' '.e($row->amount).'</dd><dt>Status</dt><dd>'.e($row->status).'</dd><dt>Issued</dt><dd>'.e($row->issued_at?->toDateString()).'</dd><dt>Due</dt><dd>'.e($row->due_at?->toDateString()).'</dd><dt>Paid</dt><dd>'.e($row->paid_at?->toDateString() ?: '—').'</dd></dl></body></html>';
        if (data_get($row->metadata, 'service_feature')) {
            $details = '<dt>Service</dt><dd>'.e(data_get($row->metadata, 'service_feature')).'</dd><dt>Service starts</dt><dd>'.e(data_get($row->metadata, 'service_starts_at')).'</dd><dt>Service expires</dt><dd>'.e(data_get($row->metadata, 'service_ends_at')).'</dd>';
            $html = str_replace('</dl>', $details.'</dl>', $html);
        }
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="invoice-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $row->invoice_number).'.html"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])]])['status'] ?? null;
        $rows = TenantPlanUpgradeRequest::query()->withoutGlobalScopes()->with(['tenant:id,name', 'currentPlan:id,name', 'requestedPlan:id,name'])
            ->when($status, fn ($query) => $query->where('status', $status))->latest()->paginate(30);
        return ApiResponse::success(['data' => collect($rows->items())->map(fn ($item) => ['uuid' => $item->uuid, 'tenant' => $item->tenant?->name, 'current_plan' => $item->currentPlan?->name, 'requested_plan' => $item->requestedPlan?->name, 'status' => $item->status, 'contact_name' => $item->contact_name, 'contact_email' => $item->contact_email, 'contact_phone' => $item->contact_phone, 'reason' => $item->reason, 'notes' => $item->notes, 'decision_note' => $item->decision_note, 'created_at' => $item->created_at?->toIso8601String()]), 'pagination' => ['total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]]);
    }

    public function decide(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['required', 'string', 'min:3', 'max:2000']]);
        $item = DB::transaction(function () use ($request, $uuid, $data) {
            $item = TenantPlanUpgradeRequest::query()->withoutGlobalScopes()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($item->status === 'pending', 409, 'Only pending requests can be decided.');
            $item->update(['status' => $data['status'], 'decision_note' => $data['note'], 'decided_by' => $request->user()->id, 'decided_at' => now()]);
            DB::table('tenant_plan_upgrade_request_events')->insert(['tenant_plan_upgrade_request_id' => $item->id, 'actor_id' => $request->user()->id, 'from_status' => 'pending', 'to_status' => $data['status'], 'note' => $data['note'], 'created_at' => now()]);
            return $item;
        });
        activity('subscription')->performedOn($item)->causedBy($request->user())->withProperties(['tenant_id' => $item->tenant_id, 'status' => $item->status])->log('Plan upgrade request decided');
        if ($item->requested_by && ($recipient = User::query()->withoutGlobalScopes()->find($item->requested_by))) {
            $this->notifications->create([
                'title' => 'Plan upgrade request updated',
                'message' => "Your request was {$item->status}. {$data['note']}",
                'type' => 'subscription',
                'severity' => $item->status === 'approved' ? 'success' : 'warning',
                'action_url' => '/admin/account',
                'payload' => ['upgrade_request_uuid' => $item->uuid, 'tenant_id' => $item->tenant_id],
            ], $recipient);
        }
        return ApiResponse::success(['uuid' => $item->uuid, 'status' => $item->status], 'Upgrade request updated.');
    }

    private function notifyAdmin(TenantPlanUpgradeRequest $upgrade): void
    {
        User::query()->withoutGlobalScopes()->permission('admin.saas.manage')->each(function (User $administrator) use ($upgrade): void {
            $this->notifications->create([
                'title' => 'Plan upgrade requested',
                'message' => "Tenant #{$upgrade->tenant_id} submitted a plan upgrade request.",
                'type' => 'subscription',
                'severity' => 'info',
                'action_url' => '/admin/saas/billing',
                'payload' => ['upgrade_request_uuid' => $upgrade->uuid, 'tenant_id' => $upgrade->tenant_id],
            ], $administrator);
        });
        $email = config('saas.workspace.support.email');
        if (! $email) return;
        try { Mail::raw("Tenant #{$upgrade->tenant_id} requested plan #{$upgrade->requested_plan_id}. Reference: {$upgrade->uuid}", fn ($message) => $message->to($email)->subject('NexDine plan upgrade request')); } catch (\Throwable $exception) { report($exception); }
    }
}
