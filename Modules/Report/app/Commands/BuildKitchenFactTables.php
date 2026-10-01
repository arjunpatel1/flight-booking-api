<?php

namespace Modules\Report\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Report\Jobs\BuildKitchenFactTablesJob;

class BuildKitchenFactTables extends Command
{
    protected $signature = 'report:build-kitchen-fact {date? : The date to build (YYYY-MM-DD, default: today)} {--branch-id= : Specific branch ID}';
    protected $description = 'Build kitchen fact tables for a specific date';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->toDateString();
        $branchId = $this->option('branch-id') ? (int) $this->option('branch-id') : null;

        $this->info("Building kitchen fact tables for date: {$date}");

        try {
            Carbon::parse($date);
        } catch (\Exception $e) {
            $this->error("Invalid date format: {$date}");
            return self::FAILURE;
        }

        BuildKitchenFactTablesJob::dispatch($date, $branchId);

        $this->info("Kitchen fact table job dispatched successfully.");
        return self::SUCCESS;
    }
}
