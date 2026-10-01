<?php

namespace Tests\Feature\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Import\Services\Adapters\MenuImportAdapter;
use Modules\Menu\Models\Menu;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

/**
 * Regression for imports that report success while nothing appears.
 *
 * filter_var('', FILTER_VALIDATE_BOOLEAN) is false, so a blank is_active cell
 * created the record with is_active = false. ActiveScope then hid it from the
 * module listing, leaving "Completed, 10 succeeded, 0 failed" and an empty
 * Menu screen.
 */
class ImportBooleanFlagsTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();

        // RefreshDatabase migrates without seeding, so the translated() helper
        // has no default locale to require.
        setting(['default_locale' => 'en']);
        app(SettingServiceInterface::class)->refreshSettingBinding();
    }

    private function importMenu(array $row): Menu
    {
        $branch = $this->makeBranch();

        app(MenuImportAdapter::class)->import(
            ['name_en' => 'Imported '.uniqid(), ...$row],
            ['branch_id' => $branch->id],
        );

        return Menu::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    public function test_a_blank_is_active_cell_still_produces_a_visible_menu(): void
    {
        $menu = $this->importMenu(['is_active' => '']);

        $this->assertTrue(
            (bool) $menu->is_active,
            'A blank cell means "not supplied" and must not hide the record.'
        );
        $this->assertTrue(
            Menu::query()->whereKey($menu->id)->exists(),
            'The imported menu must be visible through the active scope.'
        );
    }

    public function test_a_whitespace_only_cell_is_treated_as_blank(): void
    {
        $menu = $this->importMenu(['is_active' => '   ']);

        $this->assertTrue((bool) $menu->is_active);
    }

    public function test_an_absent_column_keeps_the_default(): void
    {
        $menu = $this->importMenu([]);

        $this->assertTrue((bool) $menu->is_active);
    }

    public function test_an_explicit_false_is_still_respected(): void
    {
        foreach (['0', 'false', 'no'] as $value) {
            $menu = $this->importMenu(['is_active' => $value]);

            $this->assertFalse(
                (bool) $menu->is_active,
                "An explicit \"{$value}\" must still deactivate the record."
            );
        }
    }

    public function test_an_explicit_true_is_respected(): void
    {
        foreach (['1', 'true', 'yes'] as $value) {
            $menu = $this->importMenu(['is_active' => $value]);

            $this->assertTrue((bool) $menu->is_active);
        }
    }

    public function test_an_unrecognised_value_still_fails_the_row(): void
    {
        // Null from FILTER_NULL_ON_FAILURE trips the boolean rule, so the row is
        // reported as failed rather than silently imported wrong.
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->importMenu(['is_active' => 'Active']);
    }
}
