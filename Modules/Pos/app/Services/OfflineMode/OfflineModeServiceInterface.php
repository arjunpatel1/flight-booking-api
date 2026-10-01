<?php

namespace Modules\Pos\Services\OfflineMode;

use Illuminate\Support\Collection;
use Modules\User\Models\User;

interface OfflineModeServiceInterface
{
    /** Verify that an existing terminal belongs to the authenticated user. */
    public function verifyDeviceOwnership(User $user, string $deviceId): bool;

    /**
     * Check if system is in offline mode.
     */
    public function isOffline(): bool;

    /**
     * Enable offline mode.
     */
    public function enableOfflineMode(): void;

    /**
     * Disable offline mode.
     */
    public function disableOfflineMode(): void;

    /**
     * Store order in offline queue.
     *
     * @param array $orderData
     * @return array{success: bool, order_id?: string, error?: string}
     */
    public function storeOfflineOrder(array $orderData): array;

    /**
     * Get all offline orders.
     *
     * @return Collection
     */
    public function getOfflineOrders(): Collection;

    /**
     * Get pending offline orders.
     *
     * @return Collection
     */
    public function getPendingOfflineOrders(): Collection;

    /**
     * Sync offline orders to server.
     *
     * @return array{synced: int, failed: int, errors: array}
     */
    public function syncOfflineOrders(): array;

    /**
     * Mark offline order as synced.
     *
     * @param string $orderId
     * @return bool
     */
    public function markOrderAsSynced(string $orderId): bool;

    /**
     * Delete offline order.
     *
     * @param string $orderId
     * @return bool
     */
    public function deleteOfflineOrder(string $orderId): bool;

    /**
     * Get offline mode statistics.
     *
     * @return array
     */
    public function getOfflineStatistics(): array;

    /**
     * Clear all offline data.
     *
     * @return bool
     */
    public function clearOfflineData(): bool;

    /**
     * Generate offline receipt.
     *
     * @param string $orderId
     * @return array{success: bool, receipt?: array, error?: string}
     */
    public function generateOfflineReceipt(string $orderId): array;

    /**
     * Validate offline order data.
     *
     * @param array $orderData
     * @return array{valid: bool, errors: array}
     */
    public function validateOfflineOrder(array $orderData): array;
}
