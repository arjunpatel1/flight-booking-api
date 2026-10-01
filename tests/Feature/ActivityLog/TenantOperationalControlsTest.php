<?php
namespace Tests\Feature\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\ActivityLog\Services\AuthenticationLog\AuthenticationLogService;
use Modules\Order\Delivery\PlatformDeliveryCredentials;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Models\Setting;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;
class TenantOperationalControlsTest extends TestCase
{
    use RefreshDatabase, AggregatorTestSupport;
    protected function setUp(): void { parent::setUp(); $this->setUpAggregatorTestSupport(); Http::preventStrayRequests(); }
    private function tenant(): Tenant { return Tenant::query()->withoutGlobalScopes()->create(['name'=>'Control test','slug'=>Str::random(12),'is_active'=>true]); }
    public function test_authentication_history_loads_authorized_users_and_excludes_other_tenants(): void
    {
        $tenant = $this->tenant(); $other = $this->tenant();
        app(TenantContext::class)->set($tenant);
        $branch = $this->makeBranch(['tenant_id'=>$tenant->id]);
        $actor = User::factory()->create(['tenant_id'=>$tenant->id,'branch_id'=>$branch->id]);
        $deleted = User::factory()->create(['tenant_id'=>$tenant->id,'branch_id'=>$branch->id]);
        $foreign = User::factory()->create(['tenant_id'=>$other->id,'branch_id'=>$branch->id]);
        foreach ([$actor, $deleted, $foreign] as $user) DB::table('authentication_log')->insert(['authenticatable_type'=>(new User)->getMorphClass(),'authenticatable_id'=>$user->id,'ip_address'=>'192.0.2.1','login_at'=>now()]);
        DB::table('users')->where('id',$deleted->id)->update(['deleted_at'=>now()]);
        $this->actingAs($actor);
        $rows = app(AuthenticationLogService::class)->get([])->getCollection();
        $this->assertCount(2,$rows);
        $this->assertSameCanonicalizing([$actor->id,$deleted->id],$rows->pluck('authenticatable.id')->all());
        $foreignLog = DB::table('authentication_log')->where('authenticatable_id',$foreign->id)->value('id');
        try { app(AuthenticationLogService::class)->show($foreignLog); $this->fail('Foreign authentication record exposed.'); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { $this->assertTrue(true); }
    }
    public function test_provider_credentials_come_only_from_encrypted_platform_settings(): void
    {
        $tenant=$this->tenant(); app(TenantContext::class)->set($tenant);
        foreach ([['global',null,'platform-secret','platform-store'],['tenant:'.$tenant->id,$tenant->id,'tenant-secret','tenant-store']] as [$scope,$id,$key,$store]) {
            Setting::query()->withoutGlobalScopes()->create(['tenant_id'=>$id,'branch_id'=>null,'setting_scope'=>$scope,'key'=>'delivery_uengage_api_key','is_encryptable'=>true,'is_translatable'=>false,'payload'=>$key]);
            Setting::query()->withoutGlobalScopes()->create(['tenant_id'=>$id,'branch_id'=>null,'setting_scope'=>$scope,'key'=>'delivery_uengage_store_id','is_encryptable'=>false,'is_translatable'=>false,'payload'=>$store]);
        }
        $credentials=app(PlatformDeliveryCredentials::class);
        $this->assertSame('platform-secret',$credentials->apiKey());
        $this->assertSame('platform-store',$credentials->storeId());
        $this->assertSame($tenant->id,app(TenantContext::class)->id());
        Http::assertNothingSent();
    }
    public function test_unencrypted_provider_secret_is_rejected(): void
    {
        Setting::query()->withoutGlobalScopes()->create(['tenant_id'=>null,'branch_id'=>null,'setting_scope'=>'global','key'=>'delivery_uengage_api_key','is_encryptable'=>false,'is_translatable'=>false,'payload'=>'unsafe-key']);
        $this->assertSame('',app(PlatformDeliveryCredentials::class)->apiKey());
        Http::assertNothingSent();
    }
}
