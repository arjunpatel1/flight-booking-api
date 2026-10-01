<?php

namespace Modules\Order\Services;

use Illuminate\Support\Facades\Log;

class OfflineOrderIntegrityService
{
    /**
     * Generate HMAC signature for offline order data.
     *
     * @param array $orderData
     * @param string $deviceSecret
     * @return string
     */
    public function generateSignature(array $orderData, string $deviceSecret): string
    {
        // Normalize order data for consistent signature
        $normalizedData = $this->normalizeOrderData($orderData);
        
        return hash_hmac('sha256', json_encode($normalizedData), $deviceSecret);
    }

    /**
     * Verify HMAC signature for offline order data.
     *
     * @param array $orderData
     * @param string $signature
     * @param string $deviceSecret
     * @return bool
     */
    public function verifySignature(array $orderData, string $signature, string $deviceSecret): bool
    {
        $expectedSignature = $this->generateSignature($orderData, $deviceSecret);
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Normalize order data for consistent signature generation.
     *
     * @param array $orderData
     * @return array
     */
    protected function normalizeOrderData(array $orderData): array
    {
        // Sort keys alphabetically for consistent signature
        ksort($orderData);
        
        // Remove signature from data before signing
        unset($orderData['signature']);
        
        return $orderData;
    }

    /**
     * Validate offline order data integrity.
     *
     * @param array $orderData
     * @param string $deviceSecret
     * @return array
     */
    public function validateOrderIntegrity(array $orderData, string $deviceSecret): array
    {
        $errors = [];
        
        // Check if signature exists
        if (!isset($orderData['signature'])) {
            $errors[] = 'Order signature is missing';
            return ['valid' => false, 'errors' => $errors];
        }
        
        // Verify signature
        if (!$this->verifySignature($orderData, $orderData['signature'], $deviceSecret)) {
            $errors[] = 'Invalid order signature';
        }
        
        // Check required fields
        $requiredFields = ['order_id', 'branch_id', 'table_id', 'items'];
        foreach ($requiredFields as $field) {
            if (!isset($orderData[$field])) {
                $errors[] = "Required field '{$field}' is missing";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
