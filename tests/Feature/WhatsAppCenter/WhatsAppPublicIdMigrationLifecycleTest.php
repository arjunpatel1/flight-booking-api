<?php

namespace Tests\Feature\WhatsAppCenter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;
use Modules\WhatsAppCenter\Services\WhatsAppChannelContextResolver;
use Tests\TestCase;

class WhatsAppPublicIdMigrationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_id_migration_backfills_preserves_ownership_rolls_back_and_reapplies(): void
    {
        $migration = require base_path('Modules/WhatsAppCenter/database/migrations/2026_09_07_000001_add_public_ids_to_whatsapp_control_plane.php');
        $migration->down();

        $tenant = Tenant::query()->create(['name' => 'Migration Tenant', 'slug' => 'wa-migration', 'is_active' => true]);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true, 'is_accepting_orders' => true]);
        $now = now();
        $profileId = DB::table('whatsapp_provider_profiles')->insertGetId([
            'tenant_id' => $tenant->id, 'name' => 'Existing Profile', 'ownership_mode' => 'restaurant_owned',
            'provider' => 'msg91', 'credentials' => encrypt(['auth_key' => 'encrypted-test-secret']),
            'credential_version' => 'v1', 'status' => 'connected', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $numberId = DB::table('whatsapp_phone_numbers')->insertGetId([
            'provider_profile_id' => $profileId, 'provider_phone_id' => 'migration-phone',
            'display_number' => '+910000000099', 'status' => 'connected', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $assignmentId = DB::table('whatsapp_tenant_assignments')->insertGetId([
            'tenant_id' => $tenant->id, 'provider_profile_id' => $profileId, 'phone_number_id' => $numberId,
            'ownership_mode' => 'restaurant_owned', 'allowed_branch_ids' => json_encode([$branch->id]),
            'capabilities' => json_encode(['ordering' => true]), 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $ownershipBefore = [$tenant->id, $profileId, $numberId, $assignmentId, $branch->id];
        $migration->up();

        $uuids = collect(['whatsapp_provider_profiles' => $profileId, 'whatsapp_phone_numbers' => $numberId,
            'whatsapp_tenant_assignments' => $assignmentId])->mapWithKeys(
            fn (int $id, string $table) => [$table => DB::table($table)->where('id', $id)->value('uuid')]
        );
        foreach ($uuids as $uuid) {
            $this->assertTrue(Str::isUuid($uuid));
        }
        $this->assertSame(3, $uuids->unique()->count());
        $this->assertSame($ownershipBefore, [$tenant->id, $profileId, $numberId, $assignmentId, $branch->id]);

        $resolver = app(WhatsAppChannelContextResolver::class);
        $this->assertSame($profileId, $resolver->resolveProfile($uuids['whatsapp_provider_profiles'])->id);
        $this->assertSame($profileId, $resolver->resolveProfile((string) $profileId)->id);
        $this->assertSame($assignmentId, $resolver->resolve($uuids['whatsapp_provider_profiles'], 'migration-phone')->assignmentId);

        $migration->down();
        foreach ($uuids->keys() as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'uuid'));
        }
        $this->assertDatabaseHas('whatsapp_tenant_assignments', [
            'id' => $assignmentId, 'tenant_id' => $tenant->id,
            'provider_profile_id' => $profileId, 'phone_number_id' => $numberId,
        ]);

        $migration->up();
        foreach ($uuids->keys() as $table) {
            $uuid = DB::table($table)->where('id', match ($table) {
                'whatsapp_provider_profiles' => $profileId,
                'whatsapp_phone_numbers' => $numberId,
                default => $assignmentId,
            })->value('uuid');
            $this->assertTrue(Str::isUuid($uuid));
        }
    }
}
