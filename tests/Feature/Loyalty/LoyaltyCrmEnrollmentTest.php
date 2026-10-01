<?php
namespace Tests\Feature\Loyalty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Loyalty\Models\LoyaltyCustomer;
use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Loyalty\Services\LoyaltyGift\LoyaltyGiftService;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\User\Models\User;
use Modules\User\Services\Customer\CustomerService;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;
class LoyaltyCrmEnrollmentTest extends TestCase {
 use RefreshDatabase, AggregatorTestSupport;
 private $tenant;private $branch;private $program;
 protected function setUp():void {
  parent::setUp();$this->setUpAggregatorTestSupport();
  \Modules\User\Models\Role::firstOrCreate(['name'=>'customer','guard_name'=>'api'],['display_name'=>['en'=>'Customer'],'built_in'=>true]);
  $this->tenant=Tenant::query()->withoutGlobalScopes()->create(['name'=>'Loyalty CRM','slug'=>'loyalty-crm','is_active'=>true]);
  app(TenantContext::class)->set($this->tenant);$this->branch=$this->makeBranch(['tenant_id'=>$this->tenant->id]);
  $actor=User::factory()->create(['tenant_id'=>$this->tenant->id,'branch_id'=>$this->branch->id]);$this->actingAs($actor);
  $this->program=LoyaltyProgram::factory()->create(['is_active'=>true,'created_by'=>$actor->id]);
 }
 public function test_crm_customer_is_enrolled_without_awarding_points():void {
  $customer=app(CustomerService::class)->store(['name'=>'CRM customer','tenant_id'=>$this->tenant->id,'branch_id'=>$this->branch->id,'is_active'=>true]);
  $member=LoyaltyCustomer::where('customer_id',$customer->id)->firstOrFail();
  $this->assertSame($this->program->id,$member->loyalty_program_id);$this->assertSame(0,$member->points_balance);
 }
 public function test_updating_a_customer_created_before_program_enrolls_them_once():void {
  $customer=User::factory()->create(['tenant_id'=>$this->tenant->id,'branch_id'=>$this->branch->id,'is_active'=>true]);
  $customer->assignRole(\Modules\User\Enums\DefaultRole::Customer);
  $service=app(CustomerService::class);
  $service->update($customer->id,['name'=>'Updated customer']);
  $service->update($customer->id,['name'=>'Updated again']);
  $this->assertSame(1,LoyaltyCustomer::query()->where('customer_id',$customer->id)->count());
  $member=LoyaltyCustomer::query()->where('customer_id',$customer->id)->firstOrFail();
  $this->assertSame($this->program->id,$member->loyalty_program_id);
  $this->assertSame(0,$member->points_balance);
 }
 public function test_tenant_admin_can_load_members_and_transactions_with_filter_metadata():void {
  $entitlements=\Mockery::mock(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class)->makePartial();
  $entitlements->shouldReceive('has')->andReturn(true);
  app()->instance(\Modules\Saas\Services\Entitlements\EffectiveTenantEntitlementService::class,$entitlements);
  $actor=auth()->user();
  $actor->givePermissionTo(['admin.loyalty_customers.index','admin.loyalty_transactions.index']);
  \Laravel\Sanctum\Sanctum::actingAs($actor,['*'],'api');
  $customer=app(CustomerService::class)->store(['name'=>'Visible member','tenant_id'=>$this->tenant->id,'branch_id'=>$this->branch->id,'is_active'=>true]);
  $member=LoyaltyCustomer::query()->where('customer_id',$customer->id)->firstOrFail();
  \Modules\Loyalty\Models\LoyaltyTransaction::query()->create(['loyalty_customer_id'=>$member->id,'type'=>'adjust','points'=>5,'amount'=>0]);
  $this->getJson('/api/v1/loyalty-customers?with_filters=1')->assertOk()->assertJsonPath('body.data.0.customer.id',$customer->id);
  $this->getJson('/api/v1/loyalty-transactions?with_filters=1')->assertOk()->assertJsonPath('body.data.0.customer.id',$member->id);
  $this->getJson('/api/v1/loyalty-transactions?filters[loyalty_customer_id]='.$member->id)
   ->assertOk()->assertJsonPath('body.pagination.total',1);
 }
 public function test_pos_enrolls_existing_customer_without_tier_or_free_points():void {
  $customer=User::factory()->create(['tenant_id'=>$this->tenant->id,'branch_id'=>$this->branch->id,'is_active'=>true]);
  $reward=\Modules\Loyalty\Models\LoyaltyReward::create(['name'=>['en'=>'Test discount'],'value'=>10,'value_type'=>'fixed','loyalty_program_id'=>$this->program->id,'loyalty_tier_id'=>null,'points_cost'=>100,'conditions'=>[],'starts_at'=>null,'ends_at'=>null,'type'=>'discount','is_active'=>true]);
  $result=app(LoyaltyGiftService::class)->getRewards($customer->id,$this->program->id,$this->branch->id);
  $this->assertSame($reward->id,$result['eligible'][0]['id']);
  $this->assertSame(0,$result['customer']['points_balance']);$this->assertNull($result['customer']['tier']['id']);
  app(LoyaltyGiftService::class)->getRewards($customer->id,$this->program->id,$this->branch->id);
  $this->assertSame(1,LoyaltyCustomer::where('customer_id',$customer->id)->count());
 }
 public function test_missing_relations_are_safe_in_list_resources():void {
  $member=new LoyaltyCustomer(['customer_id'=>999,'loyalty_program_id'=>999]);
  $member->setRelation('customer',null);$member->setRelation('loyaltyProgram',null);
  $data=(new \Modules\Loyalty\Transformers\Api\V1\LoyaltyCustomerResource($member))->toArray(request());
  $this->assertNull($data['customer']['name']);
  $transaction=new \Modules\Loyalty\Models\LoyaltyTransaction(['type'=>'adjust','points'=>0]);$transaction->setRelation('customer',null);
  $data=(new \Modules\Loyalty\Transformers\Api\V1\LoyaltyTransactionResource($transaction))->toArray(request());
  $this->assertSame('',$data['customer']['name']);
 }

 public function test_system_created_program_remains_available_for_crm_enrollment():void {
  $this->program->forceFill(['created_by'=>null])->save();
  $customer=app(CustomerService::class)->store(['name'=>'System program customer','tenant_id'=>$this->tenant->id,'branch_id'=>$this->branch->id,'is_active'=>true]);
  $this->assertSame($this->program->id,LoyaltyCustomer::where('customer_id',$customer->id)->firstOrFail()->loyalty_program_id);
 }

 public function test_tenant_cannot_modify_or_delete_a_shared_program():void {
  $this->program->forceFill(['created_by'=>null])->save();
  $service=app(\Modules\Loyalty\Services\LoyaltyProgram\LoyaltyProgramService::class);
  $this->assertFalse($service->destroy($this->program->id));
  $this->assertNotNull($this->program->fresh());
  try { $service->update($this->program->id,['is_active'=>false]); $this->fail('Shared program was editable.'); }
  catch (\Illuminate\Database\Eloquent\ModelNotFoundException $expected) {}
  $this->assertTrue((bool) $this->program->fresh()->is_active);
 }

 public function test_loyalty_lists_and_pos_lookup_do_not_expose_another_tenant_customer():void {
  $other=Tenant::query()->withoutGlobalScopes()->create(['name'=>'Other restaurant','slug'=>'other-loyalty','is_active'=>true]);
  $otherBranch=$this->makeBranch(['tenant_id'=>$other->id]);
  $customer=User::factory()->create(['tenant_id'=>$other->id,'branch_id'=>$otherBranch->id,'is_active'=>true]);
  $member=LoyaltyCustomer::query()->create(['customer_id'=>$customer->id,'loyalty_program_id'=>$this->program->id,'points_balance'=>50,'lifetime_points'=>50]);
  $transaction=\Modules\Loyalty\Models\LoyaltyTransaction::query()->create(['loyalty_customer_id'=>$member->id,'type'=>'adjust','points'=>50,'amount'=>0]);
  $this->assertSame(0,app(\Modules\Loyalty\Services\LoyaltyCustomer\LoyaltyCustomerService::class)->get()->total());
  $this->assertSame(0,app(\Modules\Loyalty\Services\LoyaltyTransaction\LoyaltyTransactionService::class)->get()->total());
  $this->assertSame(0,app(LoyaltyGiftService::class)->get()->total());
  try { app(\Modules\Loyalty\Services\LoyaltyTransaction\LoyaltyTransactionService::class)->show($transaction->id); $this->fail('Cross-tenant transaction was accessible.'); }
  catch (\Illuminate\Database\Eloquent\ModelNotFoundException $expected) {}
  $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
  app(\Modules\Loyalty\Services\LoyaltyCustomer\LoyaltyCustomerService::class)->show($member->id);
 }

 public function test_pos_reward_lookup_rejects_another_tenant_customer_without_enrolling_them():void {
  $other=Tenant::query()->withoutGlobalScopes()->create(['name'=>'Other restaurant','slug'=>'other-loyalty-lookup','is_active'=>true]);
  $customer=User::factory()->create(['tenant_id'=>$other->id,'is_active'=>true]);
  $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
  try { app(LoyaltyGiftService::class)->getRewards($customer->id,$this->program->id,$this->branch->id); }
  finally { $this->assertSame(0,LoyaltyCustomer::where('customer_id',$customer->id)->count()); }
 }

}
