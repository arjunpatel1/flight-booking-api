<?php

namespace Tests\Feature\Setting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class AppearanceSettingApiTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAggregatorTestSupport();
    }

    public function test_appearance_settings_can_be_read_and_updated_with_appearance_permission(): void
    {
        $this->actingAsUserWithPermissions([
            'admin.appearance.edit',
        ]);

        $this->getJson('/api/v1/settings/appearance')
            ->assertOk()
            ->assertJsonPath('body.settings.appearance_primary_color', '#F57C00')
            ->assertJsonPath('body.meta.modes.0.id', 'light');

        $this->putJson('/api/v1/settings/appearance/update', [
            'appearance_primary_color' => '#123456',
            'appearance_secondary_color' => '#654321',
            'appearance_sidebar_color' => '#111111',
            'appearance_header_color' => '#222222',
            'appearance_button_color' => '#333333',
            'appearance_table_highlight_color' => '#444444',
            'appearance_mode' => 'dark',
            'appearance_background_type' => 'default',
            'appearance_panel_background' => null,
            'appearance_gradient_background' => null,
            'appearance_system_title' => 'NexDine ERP',
            'appearance_admin_title' => 'NexDine Admin',
            'appearance_browser_title' => 'NexDine Browser',
            'appearance_footer_text' => 'NexDine',
            'appearance_login_welcome_text' => 'Welcome',
            'appearance_login_background_image' => null,
        ])->assertOk()
            ->assertJsonPath('body.app_settings.appearance.browser_title', 'NexDine Browser')
            ->assertJsonPath('body.app_settings.appearance.theme.mode', 'dark')
            ->assertJsonPath('body.app_settings.appearance.theme.colors.primary', '#123456');
    }
}
