<?php
namespace Modules\Saas\Http\Controllers\Api\V1;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\TenantServiceAssignment;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Saas\Services\Billing\SaasBillingService;
use Modules\Support\ApiResponse;
class TenantServiceController extends Controller {
    public function index(int $tenantId, EffectiveTenantEntitlementService $entitlements) {
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId);
        $effectiveFeatures = $entitlements->features($tenant);
        $items = TenantServiceAssignment::query()->where('tenant_id', $tenantId)->with(['invoice', 'events'])->latest()->limit(100)->get();
        return ApiResponse::success(['services'=>$items->map(fn ($s)=>[
            'uuid'=>$s->uuid, 'feature'=>$s->feature, 'billing_mode'=>$s->billing_mode,
            'status'=>$s->status, 'access_active'=>$s->reportsAccess($tenant, $effectiveFeatures), 'starts_at'=>$s->starts_at,
            'ends_at'=>$s->ends_at, 'reason'=>$s->reason, 'events'=>$s->events,
            'invoice'=>$s->invoice?->only(['id','invoice_number','amount','currency','status','paid_at','payment_url']),
        ])]);
    }
    public function store(Request $r, int $tenantId, EffectiveTenantEntitlementService $entitlements, SaasBillingService $billing) {
        $d=$r->validate([
            'feature'=>['required',Rule::in(['whatsapp_ordering','delivery'])],
            'billing_mode'=>['required',Rule::in(['subscription','separate','complimentary'])],
            'starts_at'=>['required','date'], 'ends_at'=>['required','date','after:starts_at','after:now'],
            'amount'=>['required_if:billing_mode,separate','nullable','numeric','decimal:0,2','gt:0','max:99999999'],
            'reason'=>['required','string','min:8','max:500'], 'idempotency_key'=>['required','uuid'],
        ]);
        $item=DB::transaction(function () use ($d,$r,$tenantId,$entitlements,$billing) {
            $tenant=Tenant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($tenantId);
            $existing=TenantServiceAssignment::query()->where('tenant_id',$tenantId)->where('idempotency_key',$d['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->feature===$d['feature'] && $existing->billing_mode===$d['billing_mode']
                    && $existing->starts_at->equalTo($d['starts_at']) && $existing->ends_at->equalTo($d['ends_at'])
                    && $existing->reason===$d['reason']
                    && ($d['billing_mode']!=='separate' || bccomp((string)$existing->invoice->amount,(string)$d['amount'],2)===0),409,'Idempotency key was used for different service terms.');
                return $existing;
            }
            $subscription=$entitlements->subscription($tenant);
            abort_unless($subscription?->plan?->is_active,422,'An active base subscription is required.');
            abort_if(TenantServiceAssignment::query()->where('tenant_id',$tenantId)->where('feature',$d['feature'])
                ->where('status','active')->where('starts_at','<',$d['ends_at'])->where('ends_at','>',$d['starts_at'])->exists(),409,'An overlapping service period already exists. Cancel it before replacing its terms.');
            if ($d['billing_mode']==='subscription') abort_unless(in_array($d['feature'],$entitlements->resolveFeatures((array)$subscription->plan->features,(array)$subscription->overrides),true),422,'Include this service in the subscription plan or feature override first.');
            $invoice=null;
            if ($d['billing_mode']==='separate') {
                $invoice=$billing->createInvoice($subscription,(float)$d['amount']);
                $invoice->update(['metadata'=>[...($invoice->metadata??[]),'service_feature'=>$d['feature'],'service_starts_at'=>$d['starts_at'],'service_ends_at'=>$d['ends_at']]]);
            }
            $item=TenantServiceAssignment::query()->create([
                ...collect($d)->except('amount')->all(), 'uuid'=>(string)Str::uuid(),'tenant_id'=>$tenantId,
                'saas_billing_invoice_id'=>$invoice?->id,'created_by'=>$r->user()->id,'status'=>'active',
            ]);
            $item->events()->create(['actor_id'=>$r->user()->id,'event'=>'assigned','reason'=>$d['reason'],'created_at'=>now()]);
            return $item;
        });
        return ApiResponse::success(['uuid'=>$item->uuid,'access_active'=>$item->grantsAccess()], 'Service terms recorded. Separately billed access requires a paid invoice.');
    }
    public function cancel(Request $r,int $tenantId,string $uuid) {
        $d=$r->validate(['reason'=>['required','string','min:8','max:500']]);
        DB::transaction(function () use ($r,$d,$tenantId,$uuid) {
            $s=TenantServiceAssignment::query()->where('tenant_id',$tenantId)->where('uuid',$uuid)->lockForUpdate()->firstOrFail();
            if ($s->status==='cancelled') return;
            $s->update(['status'=>'cancelled']);
            $s->events()->create(['actor_id'=>$r->user()->id,'event'=>'cancelled','reason'=>$d['reason'],'created_at'=>now()]);
        });
        return ApiResponse::success(null,'Service access cancelled. Invoice and refund records remain independently managed.');
    }
}
