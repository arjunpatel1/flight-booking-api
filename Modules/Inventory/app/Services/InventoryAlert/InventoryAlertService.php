<?php

namespace Modules\Inventory\Services\InventoryAlert;

use Illuminate\Support\Collection;
use Modules\Inventory\Models\Ingredient;
use Modules\Notification\Enums\NotificationSeverity;
use Modules\Notification\Models\Notification;
use Modules\Notification\Services\NotificationServiceInterface;

class InventoryAlertService implements InventoryAlertServiceInterface
{
    public function __construct(private readonly NotificationServiceInterface $notificationService)
    {
    }

    public function notifyLowStock(?int $branchId = null, int $limit = 100): int
    {
        $ingredients = $this->lowStockQuery()
            ->when($branchId, fn($query) => $query->where('branch_id', $branchId))
            ->limit($limit)
            ->get();

        return $this->createLowStockNotifications($ingredients);
    }

    public function notifyLowStockForIngredients(array|Collection $ingredientIds): int
    {
        $ids = collect($ingredientIds)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        $this->dismissRecoveredNotifications($ids);

        $ingredients = $this->lowStockQuery()
            ->whereIn('id', $ids)
            ->get();

        return $this->createLowStockNotifications($ingredients);
    }

    private function lowStockQuery()
    {
        return Ingredient::query()
            ->with(['unit:id,symbol'])
            ->whereNotNull('alert_quantity')
            ->where('alert_quantity', '>', 0)
            ->whereColumn('current_stock', '<=', 'alert_quantity');
    }

    private function createLowStockNotifications(Collection $ingredients): int
    {
        $created = 0;

        foreach ($ingredients as $ingredient) {
            if ($this->hasOpenNotification($ingredient->id)) {
                continue;
            }

            $severity = $ingredient->current_stock <= 0
                ? NotificationSeverity::Error
                : NotificationSeverity::Warning;

            $this->notificationService->create([
                'title' => __('inventory::ingredients.notifications.low_stock.title', [
                    'ingredient' => $ingredient->name,
                ]),
                'message' => __('inventory::ingredients.notifications.low_stock.message', [
                    'ingredient' => $ingredient->name,
                    'current' => $this->formatQuantity($ingredient->current_stock, $ingredient->unit?->symbol),
                    'alert' => $this->formatQuantity($ingredient->alert_quantity, $ingredient->unit?->symbol),
                ]),
                'type' => 'inventory_low_stock',
                'severity' => $severity->value,
                'icon' => 'tabler-package-off',
                'color' => $severity->color(),
                'action_url' => '/admin/ingredients',
                'payload' => [
                    'ingredient_id' => $ingredient->id,
                    'branch_id' => $ingredient->branch_id,
                    'current_stock' => $ingredient->current_stock,
                    'alert_quantity' => $ingredient->alert_quantity,
                ],
            ]);

            $created++;
        }

        return $created;
    }

    private function dismissRecoveredNotifications(Collection $ingredientIds): void
    {
        $recoveredIds = Ingredient::query()
            ->whereIn('id', $ingredientIds)
            ->whereColumn('current_stock', '>', 'alert_quantity')
            ->pluck('id');

        if ($recoveredIds->isEmpty()) {
            return;
        }

        Notification::query()
            ->where('type', 'inventory_low_stock')
            ->whereNull('dismissed_at')
            ->whereNull('archived_at')
            ->whereIn('payload->ingredient_id', $recoveredIds)
            ->update([
                'read_at' => now(),
                'dismissed_at' => now(),
            ]);
    }

    private function hasOpenNotification(int $ingredientId): bool
    {
        return Notification::query()
            ->where('type', 'inventory_low_stock')
            ->whereNull('dismissed_at')
            ->whereNull('archived_at')
            ->where('payload->ingredient_id', $ingredientId)
            ->exists();
    }

    private function formatQuantity(float|int|string|null $quantity, ?string $unit): string
    {
        return trim(sprintf('%s %s', rtrim(rtrim((string) $quantity, '0'), '.'), $unit));
    }
}
