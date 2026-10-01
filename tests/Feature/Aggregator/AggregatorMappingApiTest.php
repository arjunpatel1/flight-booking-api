<?php

namespace Tests\Feature\Aggregator;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class AggregatorMappingApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_outlet_mapping_can_be_created_and_duplicates_are_rejected(): void
    {
        $integration = $this->makeIntegration();
        $branch = $this->makeBranch();

        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('create'),
        ]);

        $this->postJson('/api/v1/aggregator-outlet-mappings', [
            'aggregator_integration_id' => $integration->id,
            'branch_id' => $branch->id,
            'external_outlet_id' => 'SWIGGY-OUTLET-1',
            'external_outlet_name' => 'Swiggy Outlet 1',
            'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('body.external_outlet_id', 'SWIGGY-OUTLET-1');

        $this->postJson('/api/v1/aggregator-outlet-mappings', [
            'aggregator_integration_id' => $integration->id,
            'branch_id' => $branch->id,
            'external_outlet_id' => 'SWIGGY-OUTLET-2',
            'external_outlet_name' => 'Duplicate Branch',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');

        $this->postJson('/api/v1/aggregator-outlet-mappings', [
            'aggregator_integration_id' => $integration->id,
            'branch_id' => $this->makeBranch()->id,
            'external_outlet_id' => 'SWIGGY-OUTLET-1',
            'external_outlet_name' => 'Duplicate External Outlet',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('external_outlet_id');
    }

    public function test_invalid_outlet_mapping_is_rejected(): void
    {
        $integration = $this->makeIntegration();

        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('create'),
        ]);

        $this->postJson('/api/v1/aggregator-outlet-mappings', [
            'aggregator_integration_id' => $integration->id,
            'branch_id' => 999999,
            'external_outlet_id' => 'MISSING-BRANCH',
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_menu_mapping_can_be_created_and_duplicate_menu_is_rejected(): void
    {
        $integration = $this->makeIntegration();
        $branch = $this->makeBranch();
        $menu = $this->makeMenu($branch);

        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('create'),
        ]);

        $this->postJson('/api/v1/aggregator-menu-mappings', [
            'aggregator_integration_id' => $integration->id,
            'menu_id' => $menu->id,
            'external_menu_id' => 'MENU-EXT-1',
            'meta' => [
                'modifiers' => [
                    ['local_modifier_id' => 1, 'external_modifier_id' => 'MOD-1'],
                ],
                'taxes' => [
                    ['local_tax_id' => 1, 'external_tax_id' => 'TAX-1'],
                ],
            ],
            'sync_enabled' => true,
        ])->assertCreated()
            ->assertJsonPath('body.external_menu_id', 'MENU-EXT-1');

        $this->postJson('/api/v1/aggregator-menu-mappings', [
            'aggregator_integration_id' => $integration->id,
            'menu_id' => $menu->id,
            'external_menu_id' => 'MENU-EXT-2',
            'sync_enabled' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('menu_id');
    }

    public function test_mapping_permission_checks_block_unapproved_staff(): void
    {
        $integration = $this->makeIntegration();
        $branch = $this->makeBranch();

        $this->actingAsUserWithPermissions([
            $this->aggregatorPermission('logs'),
        ]);

        $this->postJson('/api/v1/aggregator-outlet-mappings', [
            'aggregator_integration_id' => $integration->id,
            'branch_id' => $branch->id,
            'external_outlet_id' => 'DENIED',
            'is_active' => true,
        ])->assertForbidden();
    }
}
