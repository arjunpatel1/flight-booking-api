<?php

namespace Modules\Saas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Modules\Support\Eloquent\Model;

class CustomerAppBuild extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_BUILDING = 'building';

    public const STATUS_TESTING = 'testing';

    public const STATUS_SIGNING = 'signing';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_CANCEL_REQUESTED = 'cancel_requested';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUPERSEDED = 'superseded';

    public const ACTIVE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_QUEUED,
        self::STATUS_CLAIMED,
        self::STATUS_BUILDING,
        self::STATUS_TESTING,
        self::STATUS_SIGNING,
        self::STATUS_VERIFYING,
        self::STATUS_UPLOADING,
        self::STATUS_CANCEL_REQUESTED,
    ];

    public const LEASED_STATUSES = [
        self::STATUS_CLAIMED,
        self::STATUS_BUILDING,
        self::STATUS_TESTING,
        self::STATUS_SIGNING,
        self::STATUS_VERIFYING,
        self::STATUS_UPLOADING,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_READY,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_REVOKED,
        self::STATUS_EXPIRED,
        self::STATUS_SUPERSEDED,
    ];

    public const STATUSES = [...self::ACTIVE_STATUSES, ...self::TERMINAL_STATUSES];

    public const PLATFORMS = ['android', 'ios'];

    public const BUILD_TYPES = ['debug', 'release', 'app_bundle'];

    public const TENANT_REQUESTABLE_BUILD_TYPES = ['release', 'app_bundle'];

    protected $fillable = [
        'uuid', 'tenant_id', 'customer_app_registration_id', 'platform', 'build_type',
        'requested_version', 'status', 'source_commit', 'branding_revision',
        'build_config_revision', 'request_fingerprint', 'active_fingerprint',
        'config_snapshot', 'artifact_checksum', 'error_code', 'error_message',
        'requested_by', 'queued_at', 'started_at', 'testing_at', 'completed_at',
        'failed_at', 'cancelled_at', 'revoked_at', 'claimed_at', 'lease_expires_at',
        'worker_id', 'attempt', 'last_heartbeat_at', 'build_metadata',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $build) => $build->uuid ??= (string) Str::uuid());
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(CustomerAppRegistration::class, 'customer_app_registration_id');
    }

    public function artifact(): HasOne
    {
        return $this->hasOne(CustomerAppBuildArtifact::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    protected function casts(): array
    {
        return [
            'branding_revision' => 'integer', 'config_snapshot' => 'array',
            'queued_at' => 'datetime', 'started_at' => 'datetime', 'testing_at' => 'datetime',
            'completed_at' => 'datetime', 'failed_at' => 'datetime',
            'cancelled_at' => 'datetime', 'revoked_at' => 'datetime',
            'claimed_at' => 'datetime', 'lease_expires_at' => 'datetime',
            'last_heartbeat_at' => 'datetime', 'attempt' => 'integer',
            'build_metadata' => 'array',
        ];
    }
}
