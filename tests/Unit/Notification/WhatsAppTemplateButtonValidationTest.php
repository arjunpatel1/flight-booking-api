<?php

namespace Tests\Unit\Notification;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Modules\Setting\Enums\SettingSection;
use Modules\Setting\Http\Requests\Api\V1\SaveSettingRequest;
use PHPUnit\Framework\TestCase;

class WhatsAppTemplateButtonValidationTest extends TestCase
{
    public function test_quick_reply_and_static_url_buttons_do_not_require_variables(): void
    {
        $validator = $this->validate([$this->template([
            ['type' => 'quick_reply', 'index' => 0, 'label' => 'Confirm', 'variable' => null],
            ['type' => 'url', 'index' => 1, 'label' => 'Open menu', 'variable' => null],
        ])]);

        $this->assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
    }

    public function test_dynamic_url_variable_still_requires_a_safe_identifier(): void
    {
        $valid = $this->validate([$this->template([
            ['type' => 'url', 'index' => 0, 'label' => 'Track order', 'variable' => 'tracking_link'],
        ])]);
        $invalid = $this->validate([$this->template([
            ['type' => 'url', 'index' => 0, 'label' => 'Track order', 'variable' => 'https://unsafe.example'],
        ])]);

        $this->assertTrue($valid->passes(), json_encode($valid->errors()->toArray()));
        $this->assertTrue($invalid->fails());
        $this->assertArrayHasKey('whatsapp_templates.0.buttons.0.variable', $invalid->errors()->toArray());
    }

    private function validate(array $templates)
    {
        $request = new class extends SaveSettingRequest
        {
            public SettingSection $section = SettingSection::WhatsApp;
        };
        $rules = array_filter(
            $request->rules(),
            fn ($key) => str_starts_with($key, 'whatsapp_templates'),
            ARRAY_FILTER_USE_KEY,
        );

        return (new Factory(new Translator(new ArrayLoader, 'en')))->make(['whatsapp_templates' => $templates], $rules);
    }

    private function template(array $buttons): array
    {
        return [
            'id' => 'order_received', 'template_id' => 'order_received', 'name' => 'Order received',
            'description' => null, 'message' => 'Your order is received.', 'category' => 'utility',
            'event' => 'order_submitted', 'is_active' => true, 'namespace' => null,
            'language_code' => 'en', 'variables' => [], 'component_keys' => [], 'buttons' => $buttons,
        ];
    }
}
