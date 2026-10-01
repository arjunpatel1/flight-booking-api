<?php
namespace Tests\Feature\Saas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Models\SubscriptionPlan;
use Modules\Saas\Models\TenantSubscription;
use Modules\Saas\Models\TenantServiceAssignment;
use Modules\Saas\Http\Controllers\Api\V1\TenantServiceController;
use Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService;
use Modules\Saas\Services\Billing\SaasBillingService;
use Modules\Saas\Support\TenantContext;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;
class TenantServiceAccessTest extends TestCase {
    use RefreshDatabase, AggregatorTestSupport;
    private Tenant $tenant;
    private TenantSubscription $subscription;
    private User $actor;
    protected function setUp(): void {
        parent::setUp(); $this->setUpAggregatorTestSupport(); Queue::fake(); Http::preventStrayRequests();
        $this->tenant=Tenant::query()->withoutGlobalScopes()->create(['name'=>'Service test','slug'=>Str::random(12),'is_active'=>true]);
        app(TenantContext::class)->set($this->tenant);
        $branch=$this->makeBranch(['tenant_id'=>$this->tenant->id]);
        $this->actor=User::factory()->create(['tenant_id'=>$this->tenant->id,'branch_id'=>$branch->id]);
        $plan=SubscriptionPlan::create(['name'=>'Base','code'=>Str::random(12),'price'=>100,'currency'=>'INR','billing_cycle'=>'monthly','features'=>['pos'],'is_active'=>true]);
        $this->subscription=TenantSubscription::create(['tenant_id'=>$this->tenant->id,'subscription_plan_id'=>$plan->id,'status'=>'active','starts_at'=>now()->subDay(),'ends_at'=>now()->addMonth(),'overrides'=>[]]);
    }
    public function test_trial_plan_keeps_whatsapp_ordering_but_not_paid_delivery(): void {
        $this->subscription->plan->update(['features'=>['pos','online_ordering','whatsapp_ordering','delivery']]);
        $this->subscription->update(['status'=>'trial','trial_ends_at'=>now()->addWeek()]);
        $this->tenant->update(['settings'=>['feature_flags'=>['tenant'=>['delivery'=>true,'whatsapp_ordering'=>true]]]]);
        $features=app(EffectiveTenantEntitlementService::class)->features($this->tenant);
        $this->assertContains('online_ordering',$features);
        $this->assertNotContains('delivery',$features);
        $this->assertContains('whatsapp_ordering',$features);
    }
    public function test_explicit_complimentary_service_is_allowed_during_trial(): void {
        $this->subscription->update(['status'=>'trial','trial_ends_at'=>now()->addWeek()]);
        $this->assign('complimentary');
        $this->assertTrue(app(EffectiveTenantEntitlementService::class)->has($this->tenant,'delivery'));
    }
    private function assign(string $mode='complimentary',array $extra=[]): array {
        $data=[...['feature'=>'delivery','billing_mode'=>$mode,'starts_at'=>now()->toIso8601String(),'ends_at'=>now()->addWeek()->toIso8601String(),'reason'=>'Service test activation','idempotency_key'=>(string)Str::uuid()],...$extra];
        $r=Request::create('/services','POST',$data); $r->setUserResolver(fn()=>$this->actor);
        app(TenantServiceController::class)->store($r,$this->tenant->id,app(EffectiveTenantEntitlementService::class),app(SaasBillingService::class));
        return $data;
    }
    public function test_complimentary_access_is_dated_isolated_and_audited(): void {
        $this->assign(); $s=TenantServiceAssignment::firstOrFail();
        $this->assertTrue(app(EffectiveTenantEntitlementService::class)->has($this->tenant,'delivery'));
        $this->assertSame(1,$s->events()->count()); $this->assertNull($s->saas_billing_invoice_id);
        $other=Tenant::query()->withoutGlobalScopes()->create(['name'=>'Other','slug'=>Str::random(12),'is_active'=>true]);
        $this->assertFalse(app(EffectiveTenantEntitlementService::class)->has($other,'delivery'));
        $this->travel(8)->days(); $this->assertFalse(app(EffectiveTenantEntitlementService::class)->has($this->tenant,'delivery'));
    }
    public function test_separate_invoice_never_grants_access_before_payment_and_refund_revokes_access(): void {
        $this->assign('separate',['amount'=>'299.00']); $s=TenantServiceAssignment::with('invoice')->firstOrFail();
        $this->assertSame('299.00',$s->invoice->amount); $this->assertSame('delivery',data_get($s->invoice->metadata,'service_feature'));
        $this->assertFalse(app(EffectiveTenantEntitlementService::class)->has($this->tenant,'delivery'));
        $s->invoice->update(['status'=>'paid']); $this->assertTrue(app(EffectiveTenantEntitlementService::class)->has($this->tenant,'delivery'));
        $s->invoice->update(['status'=>'refunded']); $this->assertFalse(app(EffectiveTenantEntitlementService::class)->has($this->tenant,'delivery'));
    }
    public function test_duplicate_request_does_not_create_duplicate_invoice(): void {
        $d=$this->assign('separate',['amount'=>'10.00']); $this->assign('separate',$d);
        $this->assertSame(1,TenantServiceAssignment::count()); $this->assertSame(1,\Modules\Saas\Models\SaasBillingInvoice::count());
        $this->assertSame(1,\Modules\Saas\Models\TenantServiceEvent::count());
    }
    public function test_overlapping_terms_are_rejected(): void {
        $this->assign(); $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class); $this->assign();
    }
    public function test_subscription_mode_requires_plan_inclusion(): void {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class); $this->assign('subscription');
    }
    public function test_cancellation_revokes_access_and_preserves_history(): void {
        $this->assign(); $s=TenantServiceAssignment::firstOrFail();
        $r=Request::create('/cancel','POST',['reason'=>'Client requested cancellation']); $r->setUserResolver(fn()=>$this->actor);
        app(TenantServiceController::class)->cancel($r,$this->tenant->id,$s->uuid);
        app(TenantServiceController::class)->cancel($r,$this->tenant->id,$s->uuid);
        $this->assertFalse(app(EffectiveTenantEntitlementService::class)->has($this->tenant,'delivery'));
        $this->assertSame(2,$s->events()->count());
    }
    public function test_base_subscription_expiry_blocks_addon_access(): void {
        $this->assign(); $this->subscription->update(['status'=>'cancelled']);
        $this->assertFalse(app(EffectiveTenantEntitlementService::class)->has($this->tenant,'delivery'));
    }
    public function test_included_subscription_service_does_not_create_extra_invoice(): void {
        $this->subscription->plan->update(['features'=>['pos','delivery']]);
        $this->assign('subscription');
        $this->assertTrue(app(EffectiveTenantEntitlementService::class)->has($this->tenant,'delivery'));
        $this->assertSame(0,\Modules\Saas\Models\SaasBillingInvoice::count());
    }
    public function test_service_payment_cannot_reactivate_base_subscription_and_receipt_is_idempotent(): void {
        $this->assign('separate',['amount'=>'20.00']); $s=TenantServiceAssignment::with('invoice')->firstOrFail();
        $this->subscription->update(['status'=>'cancelled']); $this->tenant->update(['is_active'=>false]);
        $billing=app(SaasBillingService::class);
        $billing->markPaid($s->invoice,['gateway_reference'=>'manual-service-test']);
        $billing->markPaid($s->invoice,['gateway_reference'=>'manual-service-test']);
        $this->assertSame('cancelled',$this->subscription->fresh()->status);
        $this->assertFalse($this->tenant->fresh()->is_active);
        $this->assertSame(1,$s->invoice->events()->where('type','payment_completed')->count());
    }
    public function test_signed_underpayment_cannot_settle_service_invoice(): void {
        $this->assign('separate',['amount'=>'20.00']); $s=TenantServiceAssignment::with('invoice')->firstOrFail();
        $s->invoice->update(['gateway'=>'razorpay']); config(['saas.billing.razorpay.webhook_secret'=>'service-test-secret']);
        $payload=['event'=>'payment.captured','payload'=>['payment'=>['entity'=>['id'=>'pay_test','amount'=>100,'currency'=>'INR','status'=>'captured','notes'=>['invoice_number'=>$s->invoice->invoice_number]]]]];
        $body=json_encode($payload); $r=Request::create('/webhook','POST',[],[],[],[], $body);
        $r->headers->set('X-Razorpay-Signature',hash_hmac('sha256',$body,'service-test-secret'));
        try { app(\Modules\Saas\Services\Billing\SaasBillingWebhookService::class)->handleRazorpay($r); $this->fail('Underpayment accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
        $this->assertSame('issued',$s->invoice->fresh()->status);
        $this->assertFalse(app(EffectiveTenantEntitlementService::class)->has($this->tenant,'delivery'));
    }
    public function test_cross_tenant_cancellation_is_rejected(): void {
        $this->assign(); $s=TenantServiceAssignment::firstOrFail();
        $r=Request::create('/cancel','POST',['reason'=>'Cross tenant test']); $r->setUserResolver(fn()=>$this->actor);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(TenantServiceController::class)->cancel($r,$this->tenant->id+999,$s->uuid);
    }
    public function test_tenant_cannot_manage_its_own_service_access(): void {
        $this->getJson('/api/v1/tenants/'.$this->tenant->id.'/services')->assertUnauthorized();
        \Laravel\Sanctum\Sanctum::actingAs($this->actor,['*'],'api');
        $this->getJson('/api/v1/tenants/'.$this->tenant->id.'/services')->assertForbidden();
        $this->assertSame(0,TenantServiceAssignment::count());
    }
    public function test_access_reporting_follows_effective_subscription(): void {
        $this->assign(); $s=TenantServiceAssignment::firstOrFail();
        $entitlements=app(EffectiveTenantEntitlementService::class);
        $this->assertTrue($s->reportsAccess($this->tenant,$entitlements->features($this->tenant)));
        $this->subscription->update(['status'=>'cancelled']);
        $this->assertTrue($s->grantsAccess());
        $this->assertFalse($s->reportsAccess($this->tenant,$entitlements->features($this->tenant)));
    }
    public function test_scheduled_service_does_not_report_current_access(): void {
        $this->assign('complimentary',['starts_at'=>now()->addDay()->toIso8601String()]);
        $s=TenantServiceAssignment::firstOrFail();
        $this->assertFalse($s->reportsAccess($this->tenant,['delivery']));
    }

}
