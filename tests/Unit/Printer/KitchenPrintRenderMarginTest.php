<?php

namespace Tests\Unit\Printer;

use Modules\Printer\Enum\PrintContentType;
use Modules\Printer\Enum\PrinterPaperSize;
use Modules\Printer\Services\Dispatcher\PrintPayloadRenderer;
use Modules\Printer\Services\Render\PrintRenderService;
use Tests\TestCase;

class KitchenPrintRenderMarginTest extends TestCase
{
    public function test_enabled_kitchen_beep_prefixes_escpos_buzzer_command(): void
    {
        $renderer = (new \ReflectionClass(PrintPayloadRenderer::class))->newInstanceWithoutConstructor();
        $payload = base64_encode("KITCHEN TICKET\n");

        $withBeep = $renderer->addDeviceSignals(
            PrintContentType::Kitchen,
            ['settings' => ['beep' => true]],
            $payload,
            true,
        );

        $this->assertSame("\x07\x1B\x42\x03\x03KITCHEN TICKET\n", base64_decode($withBeep, true));
        $this->assertSame($payload, $renderer->addDeviceSignals(
            PrintContentType::Bill,
            ['settings' => ['beep' => true]],
            $payload,
            true,
        ));
    }

    public function test_default_kitchen_cutter_clearance_is_twelve_lines(): void
    {
        $this->assertSame(12, config('printer.escpos.kitchen_trailing_feed_lines'));
    }

    public function test_kitchen_escpos_payload_feeds_paper_before_cut(): void
    {
        config()->set('printer.escpos.fast_text_types', ['kitchen']);
        config()->set('printer.escpos.paper80_columns', 48);
        config()->set('printer.escpos.trailing_feed_lines', 5);
        config()->set('printer.escpos.kitchen_trailing_feed_lines', 12);

        $payload = [
            'order' => [
                'order_number' => 'KOT-1001',
                'type' => 'dine_in',
            ],
            'waiter' => [
                'name' => 'QA Waiter',
            ],
            'table' => [
                'name' => 'T1',
            ],
            'customer' => [
                'id' => 41,
                'name' => 'Aarav Sharma',
            ],
            'products' => [
                [
                    'name' => 'Masala Dosa',
                    'quantity' => 1,
                    'options' => [],
                ],
            ],
        ];

        $bytes = base64_decode(
            (new PrintRenderService)->renderToEscPosBase64(
                PrintContentType::Kitchen,
                $payload,
                PrinterPaperSize::Paper80mm
            ),
            true
        );

        $this->assertIsString($bytes);
        $this->assertStringContainsString('KOT', $bytes);
        $this->assertStringContainsString("\x1B\x45\x01KITCHEN COPY\n\x1B\x45\x00", $bytes);
        $this->assertStringContainsString('Masala Dosa', $bytes);
        $this->assertStringContainsString('Customer', $bytes);
        $this->assertStringContainsString('Aarav Sharma', $bytes);
        $this->assertStringContainsString("\x1D\x21\x11KOT\n", $bytes);
        $this->assertStringContainsString(str_repeat('=', 48), $bytes);
        $this->assertSame(1, substr_count($bytes, 'KITCHEN COPY'));
        $this->assertLessThan(strpos($bytes, 'KOT'), strpos($bytes, 'KITCHEN COPY'));
        $this->assertStringNotContainsString(
            "\x1B\x64",
            $bytes,
            'KOT/waiter fast print must not use ESC d feed; it can make some spooler drivers pause between chunks.'
        );
        $this->assertTrue(
            str_ends_with($bytes, str_repeat("\n", 12)."\x1D\x56\x00"),
            'Kitchen tickets must feed paper before cut so the last item/footer is not clipped.'
        );
    }

    public function test_waiter_copy_escpos_payload_uses_same_continuous_plain_text_path(): void
    {
        config()->set('printer.escpos.fast_text_types', ['waiter']);
        config()->set('printer.escpos.paper80_columns', 48);
        config()->set('printer.escpos.trailing_feed_lines', 5);
        config()->set('printer.escpos.kitchen_trailing_feed_lines', 12);

        $payload = [
            'order' => [
                'order_number' => 'W-1001',
                'type' => 'dine_in',
            ],
            'waiter' => [
                'name' => 'QA Waiter',
            ],
            'table' => [
                'name' => 'T1',
            ],
            'products' => [
                [
                    'name' => 'Plain Dosa',
                    'quantity' => 2,
                    'options' => [],
                ],
            ],
        ];

        $bytes = base64_decode(
            (new PrintRenderService)->renderToEscPosBase64(
                PrintContentType::Waiter,
                $payload,
                PrinterPaperSize::Paper80mm
            ),
            true
        );

        $this->assertIsString($bytes);
        $this->assertStringContainsString('WAITER COPY', $bytes);
        $this->assertStringContainsString('Plain Dosa', $bytes);
        $this->assertStringContainsString("\x1D\x21\x11WAITER COPY\n", $bytes);
        $this->assertStringContainsString(str_repeat('=', 48), $bytes);
        $this->assertStringNotContainsString("\x1B\x64", $bytes);
        $this->assertTrue(str_ends_with($bytes, str_repeat("\n", 12)."\x1D\x56\x00"));
    }

    public function test_multi_item_wrapped_option_kot_finishes_all_content_before_feed_and_cut(): void
    {
        config()->set('printer.escpos.fast_text_types', ['kitchen']);
        config()->set('printer.escpos.paper58_columns', 32);
        config()->set('printer.escpos.kitchen_trailing_feed_lines', 12);

        $payload = [
            'order' => [
                'order_number' => 'KOT-1002',
                'type' => 'dine_in',
            ],
            'products' => [
                [
                    'name' => 'Mix Veg Raita',
                    'quantity' => 1,
                    'options' => [],
                ],
                [
                    'name' => 'Extra Long Special Boondi Raita Family Portion',
                    'quantity' => 2,
                    'options' => [
                        [
                            'name' => 'Spice',
                            'values' => [['label' => 'Medium']],
                        ],
                    ],
                ],
            ],
        ];

        $bytes = base64_decode(
            (new PrintRenderService)->renderToEscPosBase64(
                PrintContentType::Kitchen,
                $payload,
                PrinterPaperSize::Paper58mm
            ),
            true
        );

        $this->assertIsString($bytes);
        $this->assertStringContainsString('1 x Mix Veg Raita', $bytes);
        $this->assertStringContainsString('2 x Extra Long Special Boondi', $bytes);
        $this->assertStringContainsString('Raita Family Portion', $bytes);
        $this->assertStringContainsString('+ Spice: Medium', $bytes);

        $cutSequence = str_repeat("\n", 12)."\x1D\x56\x00";
        $finalSeparator = strrpos($bytes, str_repeat('=', 32));
        $optionPosition = strpos($bytes, '+ Spice: Medium');

        $this->assertNotFalse($finalSeparator);
        $this->assertNotFalse($optionPosition);
        $this->assertGreaterThan($optionPosition, $finalSeparator);
        $this->assertTrue(str_ends_with($bytes, $cutSequence));
    }

    public function test_invoice_escpos_payload_uses_continuous_fast_text_path(): void
    {
        config()->set('printer.escpos.fast_text_types', []);
        config()->set('printer.escpos.paper80_columns', 48);
        config()->set('printer.escpos.trailing_feed_lines', 5);

        $payload = [
            'order' => [
                'order_number' => 'INV-1001',
                'reference_no' => 'ORD-REFERENCE-1001',
                'type' => 'dine_in',
                'order_date' => '2026-06-27 18:00',
            ],
            'branch' => [
                'name' => 'Ghee Dosa',
                'phone' => '9999999999',
                'tax_number' => 'GSTIN123',
            ],
            'lines' => [
                [
                    'description' => 'Masala Dosa',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'line_total_incl_tax' => 100,
                ],
            ],
            'taxes' => [
                ['name' => 'GST', 'amount' => 5],
            ],
            'allocations' => [
                ['payment' => ['method' => 'cash'], 'amount' => 100],
            ],
            'total' => 100,
            'subtotal' => 95,
            'currency_subunit' => 2,
        ];

        $bytes = base64_decode(
            (new PrintRenderService)->renderToEscPosBase64(
                PrintContentType::Invoice,
                $payload,
                PrinterPaperSize::Paper80mm
            ),
            true
        );

        $this->assertIsString($bytes);
        $this->assertStringContainsString("\x1D\x21\x11INVOICE\n", $bytes);
        $this->assertStringContainsString('Masala Dosa', $bytes);
        $this->assertStringContainsString('Order: ORD-REFERENCE-1001', $bytes);
        $this->assertStringContainsString(str_repeat('=', 48), $bytes);
        $this->assertStringNotContainsString("\x1D\x76\x30", $bytes, 'Invoice must not use raster image chunks on raw/spooler printers.');
        $this->assertStringNotContainsString("\x1B\x64", $bytes);
        $this->assertTrue(str_ends_with($bytes, str_repeat("\n", 12)."\x1D\x56\x00"));
    }

    public function test_bill_escpos_payload_uses_continuous_fast_text_path(): void
    {
        config()->set('printer.escpos.fast_text_types', []);
        config()->set('printer.escpos.paper80_columns', 48);
        config()->set('printer.escpos.trailing_feed_lines', 5);

        $payload = [
            'order' => [
                'order_number' => 'BILL-1001',
                'type' => 'takeaway',
                'order_date' => '2026-06-27 18:05',
                'subtotal' => 150,
                'total' => 150,
            ],
            'branch' => ['name' => 'Ghee Dosa'],
            'products' => [
                [
                    'name' => 'Plain Dosa',
                    'quantity' => 2,
                    'unit_price' => 75,
                    'total' => 150,
                ],
            ],
            'payments' => [],
            'total' => 150,
            'subtotal' => 150,
            'currency_subunit' => 2,
        ];

        $bytes = base64_decode(
            (new PrintRenderService)->renderToEscPosBase64(
                PrintContentType::Bill,
                $payload,
                PrinterPaperSize::Paper80mm
            ),
            true
        );

        $this->assertIsString($bytes);
        $this->assertStringContainsString("\x1D\x21\x11BILL\n", $bytes);
        $this->assertStringContainsString('Plain Dosa', $bytes);
        $this->assertStringContainsString("#  Item                     Qty     Rate     Amt\n", $bytes);
        $this->assertStringContainsString("1. Plain Dosa                 2    75.00  150.00\n", $bytes);
        $this->assertStringContainsString("Items: 1                                  Qty: 2\n", $bytes);
        $this->assertStringContainsString("Sub Total                                 150.00\n", $bytes);
        $this->assertStringContainsString("NET PAYABLE                               150.00\n", $bytes);
        $this->assertStringNotContainsString("\x1D\x76\x30", $bytes);
        $this->assertStringNotContainsString("\x1B\x64", $bytes);
        $this->assertTrue(str_ends_with($bytes, str_repeat("\n", 12)."\x1D\x56\x00"));
    }
}
