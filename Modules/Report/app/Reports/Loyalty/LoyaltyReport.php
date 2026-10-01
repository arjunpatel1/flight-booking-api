<?php

namespace Modules\Report\Reports\Loyalty;

use Modules\Loyalty\Models\LoyaltyProgram;
use Modules\Report\Report;
use Modules\Support\GlobalStructureFilters;

abstract class LoyaltyReport extends Report
{
    /** @inheritDoc */
    public function globalFilters(): array
    {
        return [
            GlobalStructureFilters::from(),
            GlobalStructureFilters::to(),
            GlobalStructureFilters::groupByDate(),
            $this->programFilter(),
        ];
    }

    /**
     * Shared loyalty program filter config.
     *
     * @return array
     */
    protected function programFilter(): array
    {
        return [
            "key" => 'loyalty_program_id',
            "label" => __('loyalty::loyalty_programs.loyalty_program'),
            "type" => 'select',
            "options" => LoyaltyProgram::list(),
        ];
    }
}
