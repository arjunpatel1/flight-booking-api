<?php

namespace Tests\Unit\Aggregator;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aggregator\Services\Validation\AggregatorMappingValidator;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Support\AggregatorTestSupport;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class AggregatorMappingValidatorTest extends TestCase
{
    use AggregatorTestSupport;
    use RefreshDatabase;

    public function test_validator_accepts_active_outlet_and_menu_mappings(): void
    {
        $integration = $this->makeIntegration();
        $branch = $this->makeBranch();
        $menu = $this->makeMenu($branch);
        $this->mapOutlet($integration, $branch);
        $this->mapMenu($integration, $menu);

        $validator = app(AggregatorMappingValidator::class);

        $this->assertNull($validator->validateOutlet($integration, $branch->id));
        $this->assertNull($validator->validateMenu($integration));
    }

    public function test_validator_reports_missing_or_disabled_mappings(): void
    {
        $integration = $this->makeIntegration();
        $branch = $this->makeBranch();

        $validator = app(AggregatorMappingValidator::class);

        $this->assertSame(
            'No active outlet mapping exists for this order branch.',
            $validator->validateOutlet($integration, $branch->id)
        );

        $this->mapOutlet($integration, $branch, [
            'is_active' => false,
        ]);

        $this->assertSame(
            'No active outlet mapping exists for this order branch.',
            $validator->validateOutlet($integration, $branch->id)
        );

        $this->assertSame(
            'No enabled menu mapping exists for this integration.',
            $validator->validateMenu($integration)
        );
    }
}
