<?php

namespace Tests\Feature\Printer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Branch\Models\Branch;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\PrintAgentPairing;
use Modules\Saas\Http\Middleware\EnsureTenantPlanFeature;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class PrintAgentPairingApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
        $this->withoutMiddleware(EnsureTenantPlanFeature::class);
    }

    public function test_restaurant_user_claims_code_and_agent_exchanges_credentials_once(): void
    {
        $challenge = $this->createChallenge('device-one');
        [$actor, $branch] = $this->restaurantActor();

        $claim = $this->postJson('/api/v1/print-agent-pairings/claim', [
            'code' => $challenge['pairing_code'],
            // Spoofable identity fields are intentionally absent from the API contract.
        ]);
        $this->assertSame(200, $claim->status(), $claim->getContent());
        $claim
            ->assertJsonPath('data.restaurant.id', $actor->tenant_id)
            ->assertJsonPath('data.branch.id', $branch->id)
            ->assertJsonPath('data.computer', 'KITCHEN-PC-01');

        $agent = PrintAgent::query()->firstOrFail();
        $this->assertSame($branch->id, $agent->branch_id);

        $exchange = $this->withHeader('X-Pairing-Token', $challenge['challenge_token'])
            ->getJson('/api/v1/agent-pairings/'.$challenge['pairing_id'].'/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.credentials.agent_id', $agent->agent_id)
            ->assertJsonPath('data.credentials.branch_id', (string) $branch->id)
            ->assertJsonPath('data.credentials.server_url', 'http://localhost/api/v1');

        $this->assertNotEmpty($exchange->json('data.credentials.agent_secret'));
        $this->withHeader('X-Pairing-Token', $challenge['challenge_token'])
            ->getJson('/api/v1/agent-pairings/'.$challenge['pairing_id'].'/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonMissingPath('data.credentials');
    }

    public function test_claim_is_single_use_and_cannot_be_replayed(): void
    {
        $challenge = $this->createChallenge('device-two');
        $this->restaurantActor();

        $claim = $this->postJson('/api/v1/print-agent-pairings/claim', ['code' => $challenge['pairing_code']]);
        $this->assertSame(200, $claim->status(), $claim->getContent());
        $this->postJson('/api/v1/print-agent-pairings/claim', ['code' => $challenge['pairing_code']])
            ->assertUnprocessable();

        $this->assertDatabaseCount('print_agents', 1);
    }

    public function test_android_agent_pairing_preserves_platform_and_identity_prefix(): void
    {
        $challenge = $this->createChallenge('android-device', 'android');
        $this->restaurantActor();

        $this->postJson('/api/v1/print-agent-pairings/claim', [
            'code' => $challenge['pairing_code'],
        ])->assertOk();

        $agent = PrintAgent::query()->firstOrFail();
        $this->assertSame('android', $agent->platform);
        $this->assertStringStartsWith('and-', $agent->agent_id);
    }

    public function test_new_challenge_revokes_older_pending_challenge_for_same_device(): void
    {
        $first = $this->createChallenge('device-three');
        $second = $this->createChallenge('device-three');

        $this->assertNotSame($first['pairing_id'], $second['pairing_id']);
        $this->withHeader('X-Pairing-Token', $first['challenge_token'])
            ->getJson('/api/v1/agent-pairings/'.$first['pairing_id'].'/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertSame('pending', PrintAgentPairing::query()->findOrFail($second['pairing_id'])->status);
    }

    /** @return array{pairing_id:string,pairing_code:string,challenge_token:string} */
    private function createChallenge(string $deviceId, string $platform = 'windows'): array
    {
        return $this->postJson('/api/v1/agent-pairings', [
            'device_public_id' => $deviceId,
            'device_name' => 'KITCHEN-PC-01',
            'platform' => $platform,
            'agent_version' => '2.1.0',
        ])->assertCreated()->json('data');
    }

    /** @return array{User,Branch} */
    private function restaurantActor(): array
    {
        $tenant = new Tenant;
        $tenant->forceFill([
            'uuid' => (string) Str::uuid(),
            'name' => 'Pairing Restaurant',
            'slug' => 'pairing-'.Str::lower(Str::random(8)),
            'domain' => 'pairing.example.test',
            'is_active' => true,
        ])->save();
        $branch = $this->makeBranch(['tenant_id' => $tenant->id]);
        $actor = $this->actingAsUserWithPermissions(['admin.print_agents.edit']);
        $actor->forceFill([
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
        ])->save();
        $actor = $actor->refresh();
        Sanctum::actingAs($actor, ['*'], 'api');

        return [$actor, $branch];
    }
}
