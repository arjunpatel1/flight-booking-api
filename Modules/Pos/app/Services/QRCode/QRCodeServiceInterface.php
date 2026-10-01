<?php

namespace Modules\Pos\Services\QRCode;

use Illuminate\Support\Collection;

interface QRCodeServiceInterface
{
    /**
     * Generate QR code for a table.
     *
     * @param int $tableId
     * @param int $branchId
     * @param string|null $customPath
     * @return array{success: bool, qr_code_url?: string, qr_data?: string, error?: string}
     */
    public function generateTableQRCode(int $tableId, int $branchId, ?string $customPath = null): array;

    /**
     * Generate QR codes for all tables in a branch.
     *
     * @param int $branchId
     * @param string|null $customPath
     * @return Collection<int, array{table_id: int, table_name: string, qr_code_url: string, qr_data: string}>
     */
    public function generateAllTableQRCodes(int $branchId, ?string $customPath = null): Collection;

    /**
     * Validate QR code data.
     *
     * @param string $qrData
     * @return array{valid: bool, table_id?: int, branch_id?: int, error?: string}
     */
    public function validateQRCode(string $qrData): array;

    /**
     * Get QR code URL for a table.
     *
     * @param int $tableId
     * @param int $branchId
     * @param string|null $customPath
     * @return string
     */
    public function getQRCodeUrl(int $tableId, int $branchId, ?string $customPath = null): string;

    /**
     * Generate QR code for menu access.
     *
     * @param int $menuId
     * @param int $branchId
     * @param string|null $customPath
     * @return array{success: bool, qr_code_url?: string, qr_data?: string, error?: string}
     */
    public function generateMenuQRCode(int $menuId, int $branchId, ?string $customPath = null): array;

    /**
     * Bulk generate QR codes for multiple tables.
     *
     * @param array<int> $tableIds
     * @param int $branchId
     * @param string|null $customPath
     * @return Collection<int, array{table_id: int, success: bool, qr_code_url?: string, error?: string}>
     */
    public function bulkGenerateTableQRCodes(array $tableIds, int $branchId, ?string $customPath = null): Collection;
}
