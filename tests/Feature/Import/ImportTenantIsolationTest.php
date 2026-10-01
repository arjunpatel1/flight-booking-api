<?php

namespace Tests\Feature\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Branch\Models\Branch;
use Modules\Import\Enums\ImportStatus;
use Modules\Import\Enums\ImportType;
use Modules\Import\Models\ImportBatch;
use Modules\Import\Services\Import\ImportServiceInterface;
use Modules\Saas\Models\Tenant;
use Modules\User\Models\User;
use Tests\TestCase;

class ImportTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_users_only_access_imports_created_inside_their_tenant(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'import-a', 'is_active' => true]);
        $tenantB = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'import-b', 'is_active' => true]);
        $branchA = Branch::factory()->create(['tenant_id' => $tenantA->id]);
        $branchB = Branch::factory()->create(['tenant_id' => $tenantB->id]);
        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'branch_id' => $branchA->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id, 'branch_id' => $branchB->id]);

        $own = $this->batch($userA, 'own.csv');
        $foreign = $this->batch($userB, 'foreign.csv');
        Sanctum::actingAs($userA, ['*'], 'api');

        $service = app(ImportServiceInterface::class);
        $this->assertSame([$own->id], collect($service->get()->items())->pluck('id')->all());
        $this->assertSame($own->id, $service->show($own->id)->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->show($foreign->id);
    }

    private function batch(User $creator, string $filename): ImportBatch
    {
        $batch = new ImportBatch([
            'type' => ImportType::Products,
            'status' => ImportStatus::Completed,
            'original_filename' => $filename,
            'source_file_path' => 'imports/'.$filename,
        ]);
        $batch->forceFill(['created_by' => $creator->id])->save();

        return $batch;
    }
}
