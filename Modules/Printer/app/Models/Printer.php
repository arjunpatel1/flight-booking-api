<?php

namespace Modules\Printer\Models;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Modules\ActivityLog\Traits\HasActivityLog;
use Modules\Branch\Traits\HasBranch;
use Modules\Printer\Enum\PrinterConnectionType;
use Modules\Printer\Enum\PrinterProviderType;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasActiveStatus;
use Modules\Support\Traits\HasCreatedBy;
use Modules\Support\Traits\HasFilters;
use Modules\Support\Traits\HasSortBy;
use Modules\Support\Traits\HasTagsCache;
use Modules\Translation\Traits\Translatable;

/**
 * @property int $id
 * @property string $name
 * @property PrinterConnectionType $connection_type
 * @property PrinterProviderType $provider_type
 * @property array|null $options
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Printer extends Model
{
    use HasActiveStatus,
        HasActivityLog,
        HasCreatedBy,
        HasTagsCache,
        HasSortBy,
        HasFilters,
        Translatable,
        HasBranch;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "name",
        "connection_type",
        "provider_type",
        "options",
        self::ACTIVE_COLUMN_NAME,
        self::BRANCH_COLUMN_NAME
    ];

    /**
     * The attributes that are translatable.
     *
     * @var array
     */
    protected array $translatable = ['name'];

    /**
     * Get a list of all printers.
     *
     * @param int|null $branchId
     * @return Collection
     */
    public static function list(?int $branchId = null): Collection
    {
        return Cache::tags("printers")
            ->rememberForever(
                makeCacheKey(
                    [
                        'printers',
                        is_null($branchId) ? 'all' : "branch-$branchId",
                        'list'
                    ],
                    false
                ),
                fn() => static::select('id', 'name')
                    ->when(!is_null($branchId), fn($query) => $query->whereBranch($branchId))
                    ->get()
                    ->map(fn(Printer $printer) => [
                        'id' => $printer->id,
                        'name' => $printer->name
                    ])
            );
    }

    /** @inheritDoc */
    public function allowedFilterKeys(): array
    {
        return [
            "search",
            "from",
            "to",
            "connection_type",
            "provider_type",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME,
        ];
    }

    /**
     * Map a Printer model to the agent-facing printer_config structure.
     *
     * @return array
     */
    public function mapPrinterConfig(): array
    {
        $options = $this->options ?? [];

        $config = match ($this->connection_type) {
            PrinterConnectionType::Tcp => [
                'type' => 'tcp',
                'agent_id' => $options['agent_id'] ?? null,
                'connection' => [
                    'host' => $options['host'] ?? '',
                    'port' => (int)($options['port'] ?? 9100),
                ],
                'settings' => [
                    'copies' => $options['copies'] ?? 1,
                    'timeout_ms' => (int)($options['timeout_ms'] ?? 5000),
                    'retries' => (int)($options['retries'] ?? 0),
                    'cut_paper' => (bool)($options['cut_paper'] ?? false),
                    'beep' => (bool)($options['beep'] ?? false),
                    'open_cash_drawer' => (bool)($options['open_cash_drawer'] ?? false),
                    'media' => $options['paper_size'] ?? "80mm",
                    'columns' => isset($options['columns']) ? (int) $options['columns'] : null,
                ],
            ],
            PrinterConnectionType::Spooler => [
                'type' => 'spooler',
                'agent_id' => $options['agent_id'] ?? null,
                'connection' => [
                    'name' => $options['spooler_name'] ?? '',
                    'host' => $options['host'] ?? null,
                ],
                'settings' => [
                    'copies' => (int)($options['copies'] ?? 1),
                    'timeout_ms' => (int)($options['timeout_ms'] ?? 10000),
                    'media' => $options['paper_size'] ?? "80mm",
                    'columns' => isset($options['columns']) ? (int) $options['columns'] : null,
                    'sides' => $options['sides'] ?? null,
                    'color_mode' => $options['color_mode'] ?? null,
                    'resolution' => $options['resolution'] ?? null,
                    'raw' => (bool)($options['raw'] ?? true),
                    'orientation' => $options['orientation'] ?? null,
                    'margins' => $options['margins'] ?? null,
                    'fallback_text_mode' => (bool)($options['fallback_text_mode'] ?? false),
                    'extra_options' => $options['extra_options'] ?? [],
                ],
            ],
            PrinterConnectionType::UsbRaw => [
                'type' => 'usbRaw',
                'agent_id' => $options['agent_id'] ?? null,
                'connection' => [
                    'device_path' => $options['device_path'] ?? null,
                    'vendor_id' => $options['vendor_id'] ?? '',
                    'product_id' => $options['product_id'] ?? '',
                    'endpoint' => $options['endpoint'] ?? null,
                    'interface_index' => $options['interface_index'] ?? null,
                    'detach_kernel_driver' => $options['detach_kernel_driver'] ?? null,
                ],
                'settings' => [
                    'copies' => (int)($options['copies'] ?? 1),
                    'timeout_ms' => (int)($options['timeout_ms'] ?? 5000),
                    'media' => $options['paper_size'] ?? "80mm",
                    'columns' => isset($options['columns']) ? (int) $options['columns'] : null,
                ],
            ],
            PrinterConnectionType::Bluetooth => [
                'type' => 'bluetooth',
                'agent_id' => $options['agent_id'] ?? null,
                'connection' => [
                    'device_path' => $options['device_path'] ?? '/dev/rfcomm0',
                    'mac_address' => $options['mac_address'] ?? null,
                    'channel' => (int) ($options['channel'] ?? 1),
                ],
                'settings' => [
                    'copies' => (int)($options['copies'] ?? 1),
                    'timeout_ms' => (int)($options['timeout_ms'] ?? 10000),
                    'media' => $options['paper_size'] ?? "80mm",
                    'columns' => isset($options['columns']) ? (int) $options['columns'] : null,
                    'cut_paper' => (bool)($options['cut_paper'] ?? false),
                    'beep' => (bool)($options['beep'] ?? false),
                ],
            ],
        };

        $config['provider_type'] = ($this->provider_type ?? PrinterProviderType::WindowsAgent)->value;

        return $config;
    }

    /** @inheritDoc */
    protected function getSortableAttributes(): array
    {
        return [
            "name",
            "connection_type",
            "provider_type",
            self::ACTIVE_COLUMN_NAME,
            self::BRANCH_COLUMN_NAME,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connection_type' => PrinterConnectionType::class,
            'provider_type' => PrinterProviderType::class,
            'options' => "array",
            self::ACTIVE_COLUMN_NAME => "boolean",
        ];
    }
}
