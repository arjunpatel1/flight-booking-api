<?php

namespace Modules\Inventory\Commands;

use Illuminate\Console\Command;
use Modules\Inventory\Services\InventoryAlert\InventoryAlertServiceInterface;

class CheckLowStockAlerts extends Command
{
    protected $signature = 'inventory:check-low-stock
        {--branch_id= : Limit alerts to one branch}
        {--limit=100 : Maximum ingredients to inspect}';

    protected $description = 'Create in-app notifications for ingredients below their alert quantity.';

    public function handle(InventoryAlertServiceInterface $alertService): int
    {
        $count = $alertService->notifyLowStock(
            branchId: $this->option('branch_id') ? (int) $this->option('branch_id') : null,
            limit: max(1, (int) $this->option('limit'))
        );

        $this->info(__('inventory::ingredients.notifications.low_stock.command_result', [
            'count' => $count,
        ]));

        return self::SUCCESS;
    }
}
