<?php

namespace Modules\Pos\Services\QRCode;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Menu\Models\OnlineMenu;
use Modules\Saas\Models\Tenant;
use Modules\SeatingPlan\Models\Table;
use SimpleSoftwareIO\QrCode\Generator;

class QRCodeService implements QRCodeServiceInterface
{
    private const DEFAULT_QR_PATH = 'qr/menu';
    private const TABLE_QR_PATH = 'qr/table';

    /** @inheritDoc */
    public function generateTableQRCode(int $tableId, int $branchId, ?string $customPath = null): array
    {
        try {
            $table = Table::where('id', $tableId)
                ->where('branch_id', $branchId)
                ->first();

            if (!$table) {
                return [
                    'success' => false,
                    'error' => 'Table not found',
                ];
            }

            // A camera scanner must receive a navigable URL. The previous QR
            // contained an internal base64 JSON blob, which a phone could not
            // open as the customer menu. The URL carries only the table UUID,
            // never a predictable table/branch id.
            $qrData = $this->publicTableMenuUrl($table, $branchId, $customPath);
            $qrCodePath = $this->generateQRCodeFile($qrData, "table_{$tableId}_{$branchId}");

            $qrCodeUrl = Storage::url($qrCodePath);

            return [
                'success' => true,
                'qr_code_url' => $qrCodeUrl,
                'qr_data' => $qrData,
                // Retain the signed payload for native scanner integrations
                // that explicitly use QRCodeService::validateQRCode().
                'legacy_qr_data' => $this->generateTableQRData($table, $branchId),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @inheritDoc */
    public function generateAllTableQRCodes(int $branchId, ?string $customPath = null): Collection
    {
        $tables = Table::where('branch_id', $branchId)->get();
        $qrCodes = collect();

        foreach ($tables as $table) {
            $result = $this->generateTableQRCode($table->id, $branchId, $customPath);
            
            $qrCodes->push([
                'table_id' => $table->id,
                'table_name' => $table->name,
                'qr_code_url' => $result['success'] ? $result['qr_code_url'] : null,
                'qr_data' => $result['success'] ? $result['qr_data'] : null,
                'error' => $result['success'] ? null : $result['error'],
            ]);
        }

        return $qrCodes;
    }

    /** @inheritDoc */
    public function validateQRCode(string $qrData): array
    {
        try {
            $decodedData = json_decode(base64_decode($qrData), true);

            if (!$decodedData || !isset($decodedData['type'])) {
                return [
                    'valid' => false,
                    'error' => 'Invalid QR code format',
                ];
            }

            switch ($decodedData['type']) {
                case 'table':
                    return $this->validateTableQRCode($decodedData);
                
                case 'menu':
                    return $this->validateMenuQRCode($decodedData);
                
                default:
                    return [
                        'valid' => false,
                        'error' => 'Unknown QR code type',
                    ];
            }
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @inheritDoc */
    public function getQRCodeUrl(int $tableId, int $branchId, ?string $customPath = null): string
    {
        $path = $customPath ?? self::TABLE_QR_PATH;
        return url("{$path}/{$branchId}/{$tableId}");
    }

    /** @inheritDoc */
    public function generateMenuQRCode(int $menuId, int $branchId, ?string $customPath = null): array
    {
        try {
            $qrData = $this->generateMenuQRData($menuId, $branchId);
            $qrCodePath = $this->generateQRCodeFile($qrData, "menu_{$menuId}_{$branchId}");

            $qrCodeUrl = Storage::url($qrCodePath);

            return [
                'success' => true,
                'qr_code_url' => $qrCodeUrl,
                'qr_data' => $qrData,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @inheritDoc */
    public function bulkGenerateTableQRCodes(array $tableIds, int $branchId, ?string $customPath = null): Collection
    {
        $results = collect();

        foreach ($tableIds as $tableId) {
            $result = $this->generateTableQRCode($tableId, $branchId, $customPath);
            
            $results->push([
                'table_id' => $tableId,
                'success' => $result['success'],
                'qr_code_url' => $result['success'] ? $result['qr_code_url'] : null,
                'error' => $result['success'] ? null : $result['error'],
            ]);
        }

        return $results;
    }

    /**
     * Generate QR code data for a table.
     */
    private function generateTableQRData(Table $table, int $branchId): string
    {
        $data = [
            'type' => 'table',
            'table_id' => $table->id,
            'table_name' => $table->name,
            'branch_id' => $branchId,
            'timestamp' => $timestamp = now()->timestamp,
            'signature' => $this->generateSignature($table->id, $branchId, $timestamp),
        ];

        return base64_encode(json_encode($data));
    }

    /**
     * Build the public customer-menu URL for a table QR code.
     *
     * Tenant domain selection is server-owned: a scanned code always opens
     * the restaurant that owns the table, rather than trusting a host supplied
     * by an anonymous browser.
     */
    private function publicTableMenuUrl(Table $table, int $branchId, ?string $customPath = null): string
    {
        $menu = OnlineMenu::query()
            ->withOutGlobalBranchPermission()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('id')
            ->firstOrFail();

        $tenantId = Branch::query()
            ->withOutGlobalBranchPermission()
            ->whereKey($branchId)
            ->value('tenant_id');
        $domain = Tenant::query()
            ->withoutGlobalScopes()
            ->whereKey($tenantId)
            ->value('domain');

        $cleanDomain = preg_replace('#^https?://#i', '', trim((string) $domain)) ?: '';
        $baseUrl = filled($cleanDomain)
            ? 'https://'.$cleanDomain
            : rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $path = trim($customPath ?: 'online-menu', '/');

        return rtrim($baseUrl, '/').'/'.$path.'/'.rawurlencode($menu->slug)
            .'?table_token='.rawurlencode((string) $table->uuid);
    }

    /**
     * Generate QR code data for a menu.
     */
    private function generateMenuQRData(int $menuId, int $branchId): string
    {
        $data = [
            'type' => 'menu',
            'menu_id' => $menuId,
            'branch_id' => $branchId,
            'timestamp' => $timestamp = now()->timestamp,
            'signature' => $this->generateSignature($menuId, $branchId, $timestamp),
        ];

        return base64_encode(json_encode($data));
    }

    /**
     * Generate QR code file and return path.
     */
    private function generateQRCodeFile(string $data, string $filename): string
    {
        $format = extension_loaded('imagick') ? 'png' : 'svg';
        $qrCode = (new Generator())
            ->format($format)
            ->size(300)
            ->margin(1)
            ->generate($data);

        $path = "qrcodes/{$filename}.{$format}";
        Storage::disk('public')->put($path, (string) $qrCode);
        
        return $path;
    }

    /**
     * Generate signature for QR code validation.
     */
    private function generateSignature(int $id, int $branchId, ?int $timestamp = null): string
    {
        $configuredSecret = trim((string) config('app.qr_secret'));
        $rootSecret = $configuredSecret !== ''
            ? $configuredSecret
            : (string) config('app.key');
        $purposeKey = hash_hmac('sha256', "nexdine:table-qr:branch:{$branchId}", $rootSecret, true);

        return hash_hmac('sha256', "{$id}:{$branchId}:" . ($timestamp ?: now()->timestamp), $purposeKey);
    }

    /**
     * Validate table QR code.
     */
    private function validateTableQRCode(array $data): array
    {
        $tableId = $data['table_id'] ?? null;
        $branchId = $data['branch_id'] ?? null;
        $signature = $data['signature'] ?? null;
        $timestamp = $data['timestamp'] ?? null;

        if (!$tableId || !$branchId || !$signature || !$timestamp) {
            return [
                'valid' => false,
                'error' => 'Missing required data',
            ];
        }

        // Verify signature
        $expectedSignature = $this->generateSignature($tableId, $branchId, (int) $timestamp);
        if (!hash_equals($expectedSignature, $signature)) {
            return [
                'valid' => false,
                'error' => 'Invalid signature',
            ];
        }

        // Check if table exists and is active
        $table = Table::where('id', $tableId)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->first();

        if (!$table) {
            return [
                'valid' => false,
                'error' => 'Table not found or inactive',
            ];
        }

        return [
            'valid' => true,
            'table_id' => $tableId,
            'branch_id' => $branchId,
        ];
    }

    /**
     * Validate menu QR code.
     */
    private function validateMenuQRCode(array $data): array
    {
        $menuId = $data['menu_id'] ?? null;
        $branchId = $data['branch_id'] ?? null;
        $signature = $data['signature'] ?? null;
        $timestamp = $data['timestamp'] ?? null;

        if (!$menuId || !$branchId || !$signature || !$timestamp) {
            return [
                'valid' => false,
                'error' => 'Missing required data',
            ];
        }

        // Verify signature
        $expectedSignature = $this->generateSignature($menuId, $branchId, (int) $timestamp);
        if (!hash_equals($expectedSignature, $signature)) {
            return [
                'valid' => false,
                'error' => 'Invalid signature',
            ];
        }

        return [
            'valid' => true,
            'branch_id' => $branchId,
        ];
    }
}
