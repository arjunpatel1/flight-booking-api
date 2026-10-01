<?php

namespace Modules\Setting\Services\Appearance;

class ThemeResolver
{
    public function resolve(array $settings): array
    {
        return [
            "mode" => $settings['appearance_mode'] ?? 'light',
            "colors" => [
                "primary" => $settings['appearance_primary_color'] ?? '#F57C00',
                "secondary" => $settings['appearance_secondary_color'] ?? '#043A63',
                "sidebar" => $settings['appearance_sidebar_color'] ?? null,
                "header" => $settings['appearance_header_color'] ?? null,
                "button" => $settings['appearance_button_color'] ?? null,
                "table_highlight" => $settings['appearance_table_highlight_color'] ?? null,
            ],
            "background" => [
                "type" => $settings['appearance_background_type'] ?? 'default',
                "panel" => $settings['appearance_panel_background'] ?? null,
                "gradient" => $settings['appearance_gradient_background'] ?? null,
            ],
        ];
    }
}
