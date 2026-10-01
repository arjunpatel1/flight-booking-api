<?php

namespace Tests\Unit\WhatsAppCenter;

use Tests\TestCase;

class WhatsAppTextMenuContractTest extends TestCase
{
    public function test_text_menu_uses_customer_friendly_numbers_instead_of_product_uuids(): void
    {
        $engine = file_get_contents(base_path('Modules/WhatsAppCenter/app/Services/WhatsAppOrderingEngine.php'));

        $this->assertStringContainsString('Send ADD <number> <qty>', $engine);
        $this->assertStringContainsString('resolveTextMenuProductId', $engine);
        $this->assertStringNotContainsString('Send ADD <item-id> <qty>', $engine);
        $this->assertStringNotContainsString('Use the item identifier shown in MENU', $engine);
    }
}
