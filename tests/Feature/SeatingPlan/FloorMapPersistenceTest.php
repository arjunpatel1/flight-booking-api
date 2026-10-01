<?php

namespace Tests\Feature\SeatingPlan;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SeatingPlan\Models\Floor;
use Modules\SeatingPlan\Models\Zone;
use Modules\SeatingPlan\Services\Floor\FloorService;
use Modules\SeatingPlan\Services\Table\TableService;
use Modules\User\Models\User;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

class FloorMapPersistenceTest extends TestCase
{
    use AggregatorTestSupport, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_saved_zone_layout_is_returned_after_reloading_floor_list(): void
    {
        $branch = $this->makeBranch();
        $floor = Floor::factory()->create(['branch_id' => $branch->id, 'name' => ['en' => 'Dining floor'], 'is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($actor);

        Floor::list($branch->id); // prime the list cache before saving
        $settings = [
            'layout_width' => 1600, 'layout_height' => 1000,
            'show_grid' => true, 'show_guide_lines' => true,
            'show_zone_labels' => true, 'show_table_labels' => true,
            'compact_tables' => false,
            'zone_layouts' => ['91' => ['left' => 123, 'top' => 45, 'width' => 620, 'height' => 380]],
            'layout_elements' => [],
        ];
        app(FloorService::class)->updateMapSettings($floor->id, $settings);

        $reloaded = Floor::list($branch->id)->firstWhere('id', $floor->id);
        $this->assertEquals($settings['zone_layouts'], $reloaded['zone_layouts']);
        $this->assertEquals($settings['zone_layouts'], $floor->fresh()->zone_layouts);
    }

    public function test_table_form_meta_lists_zones_for_selected_branch_and_floor(): void
    {
        $branch = $this->makeBranch();
        $floor = Floor::factory()->create(['branch_id' => $branch->id, 'name' => ['en' => 'Dining floor'], 'is_active' => true]);
        $zone = Zone::factory()->create(['branch_id' => $branch->id, 'floor_id' => $floor->id, 'name' => ['en' => 'Window zone'], 'is_active' => true]);

        $metadata = app(TableService::class)->getFormMeta($branch->id, $floor->id);
        $this->assertContains($zone->id, collect($metadata['zones'])->pluck('id')->all());
    }
}
