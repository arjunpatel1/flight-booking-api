<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Support\Eloquent\Model;

/**
 * Infrastructure Registry entry — one per tenant.
 *
 * Control-plane only; carries no restaurant data. See the migration for the
 * Phase-1 contract (descriptive, not authoritative).
 *
 * @property int $tenant_id
 * @property string $mode
 * @property string $connection_status
 * @property string $health_status
 */
class TenantInfrastructure extends Model
{
    public const MODE_SHARED = 'shared';
    public const MODE_DEDICATED = 'dedicated';
    public const MODE_CLUSTER = 'cluster';

    protected $table = 'saas_tenant_infrastructure';

    protected $fillable = [
        'tenant_id', 'mode',
        'db_type', 'db_driver', 'db_host', 'db_port', 'db_name', 'db_username', 'db_password',
        'storage_disk', 'redis_prefix', 'queue_name', 'reverb_namespace',
        'connection_status', 'migration_version', 'health_status',
        'last_health_check_at', 'latency_ms',
        'backup_status', 'last_backup_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            // Credentials never appear in plaintext at rest or in a serialized model.
            'db_password' => 'encrypted',
            'meta' => 'array',
            'db_port' => 'integer',
            'latency_ms' => 'integer',
            'last_health_check_at' => 'datetime',
            'last_backup_at' => 'datetime',
        ];
    }

    /**
     * The db_password is write-only from the API's perspective — it must never
     * be exposed, even to a platform operator, through a resource or toArray.
     */
    protected $hidden = ['db_password'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * A tenant is still on the shared database unless it has been explicitly
     * moved to dedicated/cluster. Absence of a row means shared, too.
     */
    public function isShared(): bool
    {
        return $this->mode === self::MODE_SHARED;
    }

    public function isDedicated(): bool
    {
        return in_array($this->mode, [self::MODE_DEDICATED, self::MODE_CLUSTER], true);
    }

    /**
     * Whether this registry row carries enough to open a connection. In Phase 1
     * this is expected to be false for every tenant — nothing is provisioned yet.
     */
    public function hasConnectionConfig(): bool
    {
        return $this->isDedicated()
            && filled($this->db_host)
            && filled($this->db_name)
            && filled($this->db_username);
    }
}
