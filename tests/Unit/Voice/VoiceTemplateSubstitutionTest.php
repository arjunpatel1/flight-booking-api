<?php

namespace Tests\Unit\Voice;

use Modules\Voice\Models\VoiceTemplate;
use Modules\Voice\Models\VoiceSetting;
use Modules\Voice\Events\VoiceAnnouncementTriggered;
use Tests\TestCase;

class VoiceTemplateSubstitutionTest extends TestCase
{
    public function test_broadcast_keeps_nested_and_legacy_voice_clarity_settings(): void
    {
        $settings = new VoiceSetting();
        $settings->forceFill([
            'voice_gender' => 'Male',
            'voice_rate' => -1,
            'voice_volume' => 90,
            'selected_device_id' => 'speaker-1',
            'selected_device_name' => 'Kitchen speaker',
        ]);

        $payload = (new VoiceAnnouncementTriggered(1, 2, 3, 'Order 42 is ready.', 'OrderReady', $settings))->broadcastWith();

        $this->assertSame('Male', data_get($payload, 'voice.gender'));
        $this->assertSame(-1, data_get($payload, 'voice.rate'));
        $this->assertSame(90, $payload['volume']);
        $this->assertSame('speaker-1', $payload['device_id']);
    }

    private function template(string $text): VoiceTemplate
    {
        $template = new VoiceTemplate();
        $template->template_text = $text;

        return $template;
    }

    public function test_it_substitutes_every_supplied_variable(): void
    {
        $text = $this->template('New order received. Table {TableNumber}. Waiter {WaiterName}.')
            ->substituteVariables(['TableNumber' => 'T5', 'WaiterName' => 'Ramesh']);

        $this->assertSame('New order received. Table T5. Waiter Ramesh.', $text);
    }

    public function test_it_drops_the_fragment_when_a_variable_is_missing(): void
    {
        // Takeaway orders have no table and may have no assigned waiter.
        $text = $this->template('New order received. Table {TableNumber}. Waiter {WaiterName}.')
            ->substituteVariables(['OrderNumber' => 'ORD-1']);

        $this->assertSame('New order received.', $text);
    }

    public function test_it_drops_the_fragment_when_a_variable_is_blank(): void
    {
        $text = $this->template('New order received. Table {TableNumber}. Waiter {WaiterName}.')
            ->substituteVariables(['TableNumber' => 'T7', 'WaiterName' => '']);

        $this->assertSame('New order received. Table T7.', $text);
    }

    public function test_it_never_speaks_a_raw_placeholder(): void
    {
        $cases = [
            ['Order {OrderNumber} is ready.', []],
            ['Warning. Table {TableNumber} order delayed by {DelayMinutes} minutes.', []],
            ['New order received. Table {TableNumber}. Waiter {WaiterName}.', ['TableNumber' => '']],
            ['Table {TableNumber} is ready.', ['TableNumber' => null]],
        ];

        foreach ($cases as [$templateText, $variables]) {
            $text = $this->template($templateText)->substituteVariables($variables);

            $this->assertDoesNotMatchRegularExpression('/\{\w+\}/', $text, "Placeholder leaked from: {$templateText}");
            $this->assertNotSame('', trim($text), "Announcement went empty for: {$templateText}");
        }
    }

    public function test_it_falls_back_to_stripping_when_every_fragment_is_variable_dependent(): void
    {
        $text = $this->template('Table {TableNumber} is ready.')->substituteVariables([]);

        $this->assertSame('Table is ready.', $text);
    }

    public function test_it_leaves_templates_without_variables_untouched(): void
    {
        $text = $this->template('New Swiggy order received.')->substituteVariables([]);

        $this->assertSame('New Swiggy order received.', $text);
    }
}
