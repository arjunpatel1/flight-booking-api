<?php

namespace Modules\Loyalty\Console;

use Illuminate\Console\Command;
use Modules\Loyalty\Services\LoyaltyGift\LoyaltyGiftServiceInterface;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

class ExpireLoyaltyGifts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loyalty:expire-gifts {--batch=100 : Number of gifts to process per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire unused loyalty gifts that have passed their valid_until date.';

    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(LoyaltyGiftServiceInterface $loyalty): int
    {
        $batchSize = (int)$this->option('batch');

        $this->info("🔍 Scanning for expired loyalty gifts (batch size: {$batchSize})...");

        $totalExpired = 0;

        while (true) {
            $expired = $loyalty->expireGifts($batchSize);

            if ($expired <= 0) {
                $this->info('✅ No more expired gifts found.');
                break;
            }

            $totalExpired += $expired;

            $this->info("➡️  Expired {$expired} gift(s) in this batch. Total expired so far: {$totalExpired}");

            usleep(500000); // Half-second pause between batches
        }

        $this->info("🎉 Done! Total expired gifts: {$totalExpired}");

        return CommandAlias::SUCCESS;
    }
}
