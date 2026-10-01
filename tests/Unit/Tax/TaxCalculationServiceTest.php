<?php

namespace Tests\Unit\Tax;

use Modules\Tax\Enums\TaxType;
use Modules\Tax\Services\TaxCalculationService;
use PHPUnit\Framework\TestCase;

class TaxCalculationServiceTest extends TestCase
{
    private TaxCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TaxCalculationService();
    }

    public function test_exclusive_tax_is_added_on_top_of_taxable_amount(): void
    {
        $taxes = collect([
            $this->tax(10, TaxType::Exclusive),
        ]);

        $rows = $this->service->calculate(100, $taxes);

        $this->assertSame(100.0, $this->service->taxableAmount(100, $taxes));
        $this->assertEqualsWithDelta(10.0, $rows->sum('amount'), 0.0001);
        $this->assertEqualsWithDelta(10.0, $this->service->additiveTax(100, $taxes), 0.0001);
    }

    public function test_inclusive_tax_is_extracted_without_being_added_again(): void
    {
        $taxes = collect([
            $this->tax(10, TaxType::Inclusive),
        ]);

        $rows = $this->service->calculate(100, $taxes);

        $this->assertEqualsWithDelta(90.9091, $this->service->taxableAmount(100, $taxes), 0.0001);
        $this->assertEqualsWithDelta(9.0909, $rows->sum('amount'), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->service->additiveTax(100, $taxes), 0.0001);
    }

    public function test_compound_exclusive_tax_uses_running_base(): void
    {
        $taxes = collect([
            $this->tax(10, TaxType::Exclusive),
            $this->tax(5, TaxType::Exclusive, true),
        ]);

        $rows = $this->service->calculate(100, $taxes);

        $this->assertEqualsWithDelta(15.5, $rows->sum('amount'), 0.0001);
        $this->assertEqualsWithDelta(15.5, $this->service->additiveTax(100, $taxes), 0.0001);
    }

    public function test_mixed_inclusive_and_exclusive_tax_uses_net_base_for_exclusive_tax(): void
    {
        $taxes = collect([
            $this->tax(10, TaxType::Inclusive),
            $this->tax(5, TaxType::Exclusive),
        ]);

        $rows = $this->service->calculate(100, $taxes);

        $this->assertEqualsWithDelta(90.9091, $this->service->taxableAmount(100, $taxes), 0.0001);
        $this->assertEqualsWithDelta(13.6364, $rows->sum('amount'), 0.0001);
        $this->assertEqualsWithDelta(4.5455, $this->service->additiveTax(100, $taxes), 0.0001);
    }

    private function tax(float $rate, TaxType $type, bool $compound = false): object
    {
        return (object) [
            'rate' => $rate,
            'type' => $type,
            'compound' => $compound,
        ];
    }
}
