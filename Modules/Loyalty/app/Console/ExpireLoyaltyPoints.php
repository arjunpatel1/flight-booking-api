<?php

namespace Modules\Loyalty\Console;

use Illuminate\Console\Command;
use Modules\Loyalty\Services\Loyalty\LoyaltyServiceInterface;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;


class ExpireLoyaltyPoints extends Command
{
    /**
     * The name and signature of the console command.
     *
     * You can run it manually with:
     * php artisan loyalty:expire-points
     */
    protected $signature = 'loyalty:expire-points {--batch=200 : Number of customers to process per batch}';

    /**
     * The console command description.
     */
    protected $description = 'Expire loyalty points that have passed their valid_until date';

    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(LoyaltyServiceInterface $loyalty): int
    {
        $batch = (int)$this->option('batch') ?: 200;
        $totalExpired = 0;

        $this->info('🔄 Starting loyalty points expiration process...');

        // Process in repeated batches until no more expired points
        while (true) {
            $expired = $loyalty->expirePoints($batch);

            if ($expired <= 0) {
                $this->info('✅ No more expired points to process.');
                break;
            }

            $totalExpired += $expired;

            $this->info("➡️  Expired {$expired} points in this batch. Total so far: {$totalExpired}");

            // Prevent DB overload
            usleep(500000); // 0.5 second pause between batches
        }

        $this->info("🎉 Done! Total expired points: {$totalExpired}");
        return CommandAlias::SUCCESS;
    }
}
