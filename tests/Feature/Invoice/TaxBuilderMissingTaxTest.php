<?php

namespace Tests\Feature\Invoice;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Invoice\Models\Invoice;
use Modules\Invoice\Models\InvoiceTax;
use Modules\Invoice\Services\TaxBuilder\TaxBuilderServiceInterface;
use Modules\Order\Models\Order;
use Modules\Tax\Models\Tax;
use Tests\TestCase;

class TaxBuilderMissingTaxTest extends TestCase
{
    use RefreshDatabase;

    private function makeTax(array $overrides = []): Tax
    {
        $tax = new Tax();
        $tax->forceFill([
            'name' => ['en' => 'GST 5'],
            'code' => 'GST5',
            'rate' => 5,
            'is_global' => 1,
            'is_active' => 1,
            ...$overrides,
        ])->save();

        return $tax->refresh();
    }

    private function makeOrderWithTax(int $taxId): Order
    {
        $branch = Branch::factory()->create();

        $orderId = DB::table('orders')->insertGetId([
            'branch_id' => $branch->id,
            'reference_no' => 'TAXTEST-'.Str::random(6),
            'order_number' => 'TAXTEST',
            'type' => 'takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'currency' => 'INR',
            'order_date' => now(),
            'subtotal' => 100,
            'total' => 105,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_taxes')->insert([
            'order_id' => $orderId,
            'tax_id' => $taxId,
            'name' => json_encode(['en' => 'GST 5']),
            'rate' => 5,
            'currency' => 'INR',
            'currency_rate' => 1,
            'amount' => 5,
            'type' => 'exclusive',
            'compound' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::withoutGlobalScopes()->with('taxes')->findOrFail($orderId);
    }

    private function makeInvoice(): Invoice
    {
        $sellerId = DB::table('invoice_parties')->insertGetId([
            'type' => 'seller',
            'legal_name' => 'NexDine Test Seller',
            'country_code' => 'IN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoice = new Invoice();
        $invoice->forceFill([
            'uuid' => (string) Str::uuid(),
            'invoice_number' => 'INV-'.Str::random(6),
            'currency' => 'INR',
            'subtotal' => 100,
            'discount_total' => 0,
            'tax_total' => 5,
            'total' => 105,
            'issued_at' => now(),
            'seller_party_id' => $sellerId,
        ])->save();

        return $invoice->refresh();
    }

    /**
     * Regression for the production error
     *   Attempt to read property "code" on null at TaxBuilderService.php:42
     *
     * Tax carries HasActiveStatus + SoftDeletes + HasBranch, so once a tax is
     * deactivated the relation on a historical order resolves to null and
     * invoice creation aborted outright.
     */
    public function test_it_builds_invoice_taxes_when_the_tax_is_deactivated(): void
    {
        $tax = $this->makeTax();
        $order = $this->makeOrderWithTax($tax->id);
        $invoice = $this->makeInvoice();

        // Deactivate: the ActiveScope now hides it from the relation.
        DB::table('taxes')->where('id', $tax->id)->update(['is_active' => 0]);

        app(TaxBuilderServiceInterface::class)
            ->createInvoiceTaxes($invoice, collect([$order]));

        $row = InvoiceTax::withoutGlobalScopes()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($row, 'An invoice tax row must still be written.');
        $this->assertSame('GST5', $row->code, 'The original tax code belongs on the invoice.');
    }

    public function test_it_builds_invoice_taxes_when_the_tax_is_soft_deleted(): void
    {
        $tax = $this->makeTax();
        $order = $this->makeOrderWithTax($tax->id);
        $invoice = $this->makeInvoice();

        DB::table('taxes')->where('id', $tax->id)->update(['deleted_at' => now()]);

        app(TaxBuilderServiceInterface::class)
            ->createInvoiceTaxes($invoice, collect([$order]));

        $row = InvoiceTax::withoutGlobalScopes()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('GST5', $row->code);
    }

    public function test_it_survives_a_tax_row_that_no_longer_exists(): void
    {
        $tax = $this->makeTax();
        $order = $this->makeOrderWithTax($tax->id);
        $invoice = $this->makeInvoice();

        // Hard delete leaves nothing to resolve; the code column is NOT NULL so
        // it must degrade to an empty string rather than blow up. The FK on
        // order_taxes.tax_id is ON DELETE SET NULL, so reload to pick that up
        // the way a real request would.
        DB::table('taxes')->where('id', $tax->id)->delete();
        $order = Order::withoutGlobalScopes()->with('taxes')->findOrFail($order->id);

        app(TaxBuilderServiceInterface::class)
            ->createInvoiceTaxes($invoice, collect([$order]));

        $row = InvoiceTax::withoutGlobalScopes()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('', $row->code);
    }

    public function test_it_still_records_the_code_for_a_live_tax(): void
    {
        $tax = $this->makeTax(['code' => 'VAT12']);
        $order = $this->makeOrderWithTax($tax->id);
        $invoice = $this->makeInvoice();

        app(TaxBuilderServiceInterface::class)
            ->createInvoiceTaxes($invoice, collect([$order]));

        $row = InvoiceTax::withoutGlobalScopes()->where('invoice_id', $invoice->id)->first();
        $this->assertSame('VAT12', $row->code);
        // SQLite does not retain MySQL's DECIMAL display scale, but the stored
        // monetary value must remain exact across both supported test drivers.
        $this->assertEqualsWithDelta(5.0, (float) $row->getRawOriginal('amount'), 0.0001);
    }
}
