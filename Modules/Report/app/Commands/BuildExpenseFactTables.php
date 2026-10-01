<?php

namespace Modules\Report\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Report\Jobs\BuildExpenseFactTablesJob;

class BuildExpenseFactTables extends Command
{
    protected $signature = 'report:build-expense-fact {date? : The date to build (YYYY-MM-DD, default: today)} {--branch-id= : Specific branch ID}';
    protected $description = 'Build expense fact tables for a specific date';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->toDateString();
        $branchId = $this->option('branch-id') ? (int) $this->option('branch-id') : null;

        $this->info("Building expense fact tables for date: {$date}");

        try {
            Carbon::parse($date);
        } catch (\Exception $e) {
            $this->error("Invalid date format: {$date}");
            return self::FAILURE;
        }

        BuildExpenseFactTablesJob::dispatch($date, $branchId);

        $this->info("Expense fact table job dispatched successfully.");
        return self::SUCCESS;
    }
}
