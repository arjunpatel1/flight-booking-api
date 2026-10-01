<?php
namespace Tests\Unit\Notification;
use Modules\Notification\Services\Channels\EmailChannel;
use Tests\TestCase;
class OnboardingEmailLayoutTest extends TestCase
{
    public function test_secure_setup_link_is_rendered_as_a_button_and_fallback(): void
    {
        $html = (new EmailChannel)->renderLayout('Setup', 'Welcome', '', 'NexDine', '', '#F57C00', '', 'https://example.com/onboarding?invite=test&source=mail');
        $this->assertStringContainsString('Complete restaurant setup</a>', $html);
        $this->assertStringContainsString('Or open this secure link:', $html);
        $this->assertStringContainsString('href="https://example.com/onboarding?invite=test&amp;source=mail"', $html);
    }
    public function test_untrusted_action_schemes_are_not_rendered(): void
    {
        foreach (['javascript:alert(1)', 'http://example.com/setup', 'data:text/html,test'] as $url) {
            $html = (new EmailChannel)->renderLayout('Setup', 'Welcome', '', 'NexDine', '', '#F57C00', '', $url);
            $this->assertStringNotContainsString('Complete restaurant setup</a>', $html);
            $this->assertStringNotContainsString($url, $html);
        }
    }
    public function test_existing_notification_has_no_onboarding_button(): void
    {
        $html = (new EmailChannel)->renderLayout('Order ready', 'Your order is ready.', '', 'Restaurant', '', '#F57C00');
        $this->assertStringContainsString('Your order is ready.', $html);
        $this->assertStringNotContainsString('Complete restaurant setup</a>', $html);
    }
}
