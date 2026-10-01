<?php

namespace Modules\Pos\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Traits\HasBranch;
use Modules\Support\Eloquent\Model;
use Modules\Support\Traits\HasCreatedBy;

class PosTerminalDevice extends Model
{
    use HasBranch,
        HasCreatedBy,
        SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'branch_id',
        'pos_register_id',
        'pos_session_id',
        'device_id',
        'name',
        'status',
        'is_disabled',
        'disabled_at',
        'disabled_reason',
        'disabled_by',
        'app_version',
        'platform',
        'browser',
        'ip_address',
        'user_agent',
        'push_token',
        'local_queue_count',
        'server_queue_count',
        'last_seen_at',
        'last_offline_at',
        'last_sync_at',
        'meta',
        'created_by',
    ];

    protected $casts = [
        'is_disabled' => 'boolean',
        'disabled_at' => 'datetime',
        'local_queue_count' => 'integer',
        'server_queue_count' => 'integer',
        'last_seen_at' => 'datetime',
        'last_offline_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'meta' => 'array',
        'push_token' => 'encrypted',
    ];

    protected $hidden = [
        'push_token',
    ];

    public function posRegister(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    public function posSession(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /**
     * Compute the control directives delivered to a terminal on heartbeat.
     *
     * The client must act on these immediately:
     *  - force_logout: terminal disabled remotely (compromised/decommissioned)
     *  - must_upgrade: running app_version below the configured minimum
     *
     * @return array{force_logout: bool, disabled_reason: string|null, must_upgrade: bool, min_app_version: string|null}
     */
    public function controlDirectives(?string $minAppVersion = null): array
    {
        $appVersion = $this->loadedAttribute('app_version');
        $isDisabled = (bool) $this->loadedAttribute('is_disabled', false);
        $disabledReason = $this->loadedAttribute('disabled_reason');

        $mustUpgrade = $minAppVersion !== null
            && $appVersion !== null
            && self::compareVersions((string) $appVersion, $minAppVersion) < 0;

        return [
            'force_logout' => $isDisabled,
            'disabled_reason' => $isDisabled ? $disabledReason : null,
            'must_upgrade' => $mustUpgrade,
            'min_app_version' => $minAppVersion,
        ];
    }

    /**
     * Semver-style numeric comparison tolerant of suffixes (e.g. "1.2.3+4",
     * "1.2.3-rc1"). Returns -1, 0 or 1. Non-numeric/malformed input is treated
     * conservatively so a parse failure never forces an upgrade loop.
     */
    public static function compareVersions(string $a, string $b): int
    {
        $normalize = static function (string $version): ?array {
            $core = preg_split('/[-+]/', trim($version))[0] ?? '';

            if (! preg_match('/^\d+(?:\.\d+){0,2}$/', $core)) {
                return null;
            }

            $parts = array_map('intval', explode('.', $core));
            return array_pad($parts, 3, 0);
        };

        $left = $normalize($a);
        $right = $normalize($b);

        if ($left === null || $right === null) {
            return 0;
        }

        for ($i = 0; $i < 3; $i++) {
            if (($left[$i] ?? 0) !== ($right[$i] ?? 0)) {
                return ($left[$i] ?? 0) < ($right[$i] ?? 0) ? -1 : 1;
            }
        }

        return 0;
    }

    public function toStatusPayload(int $offlineAfterSeconds = 90, ?string $minAppVersion = null): array
    {
        $isOnline = $this->last_seen_at?->greaterThanOrEqualTo(now()->subSeconds($offlineAfterSeconds)) ?? false;
        $isDisabled = (bool) $this->loadedAttribute('is_disabled', false);
        $disabledAt = $this->loadedAttribute('disabled_at');
        $status = $isOnline ? (string) ($this->status ?: 'online') : 'offline';
        $queueCount = (int) $this->local_queue_count + (int) $this->server_queue_count;
        $health = $this->healthPayload($status, $queueCount, $minAppVersion);

        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'name' => $this->name,
            'status' => $status,
            'is_disabled' => $isDisabled,
            'disabled_at' => $disabledAt?->toISOString(),
            'disabled_reason' => $this->loadedAttribute('disabled_reason'),
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'pos_register_id' => $this->pos_register_id,
            'pos_register_name' => $this->posRegister?->name,
            'pos_session_id' => $this->pos_session_id,
            'app_version' => $this->app_version,
            'platform' => $this->platform,
            'browser' => $this->browser,
            'ip_address' => $this->ip_address,
            'local_queue_count' => $this->local_queue_count,
            'server_queue_count' => $this->server_queue_count,
            'queue_count' => $queueCount,
            'battery_level' => $this->metaValue('battery_level'),
            'battery_charging' => $this->metaValue('battery_charging'),
            'device_model' => $this->metaValue('device_model'),
            'os_version' => $this->metaValue('os_version'),
            'build_mode' => $this->metaValue('build_mode'),
            'crash_count' => (int) ($this->metaValue('crash_count') ?? 0),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
            'last_offline_at' => $this->last_offline_at?->toISOString(),
            'last_sync_at' => $this->last_sync_at?->toISOString(),
            'seconds_since_seen' => $this->last_seen_at ? (int) now()->diffInSeconds($this->last_seen_at, true) : null,
            'health' => $health,
            'health_score' => $health['score'],
            'health_status' => $health['status'],
            'health_issues' => $health['issues'],
            'push_registered' => filled($this->push_token),
            'meta' => $this->meta,
        ];
    }

    /**
     * Convert heartbeat telemetry into a support-friendly health signal. This
     * keeps the fleet dashboard deterministic and avoids every UI guessing why a
     * device needs attention.
     */
    private function healthPayload(string $status, int $queueCount, ?string $minAppVersion = null): array
    {
        $score = 100;
        $issues = [];
        $appVersion = $this->loadedAttribute('app_version');
        $batteryLevel = $this->metaValue('battery_level');
        $batteryCharging = (bool) ($this->metaValue('battery_charging') ?? false);
        $crashCount = (int) ($this->metaValue('crash_count') ?? 0);

        $addIssue = function (string $code, string $severity, string $message, int $penalty) use (&$score, &$issues): void {
            $score -= $penalty;
            $issues[] = compact('code', 'severity', 'message');
        };

        if ((bool) $this->loadedAttribute('is_disabled', false)) {
            $addIssue('disabled', 'critical', 'Terminal is disabled by admin.', 60);
        }

        if ($status === 'offline') {
            $addIssue('offline', 'warning', 'Terminal has missed recent heartbeats.', 25);
        } elseif ($status === 'error') {
            $addIssue('error', 'critical', 'Terminal is reporting an error state.', 40);
        } elseif ($status === 'syncing') {
            $addIssue('syncing', 'warning', 'Terminal is still syncing local work.', 10);
        }

        if ($queueCount >= 10) {
            $addIssue('queue_backlog', 'critical', 'Terminal has a large sync queue backlog.', 35);
        } elseif ($queueCount > 0) {
            $addIssue('queue_pending', 'warning', 'Terminal has pending local/server queue work.', 15);
        }

        if ($crashCount >= 3) {
            $addIssue('crashes', 'critical', 'Terminal reported repeated app crashes.', 35);
        } elseif ($crashCount > 0) {
            $addIssue('crashes', 'warning', 'Terminal reported a recent app crash.', 15);
        }

        if (is_numeric($batteryLevel) && ! $batteryCharging) {
            $batteryLevel = (int) $batteryLevel;
            if ($batteryLevel <= 10) {
                $addIssue('battery_low', 'critical', 'Terminal battery is critically low.', 30);
            } elseif ($batteryLevel <= 20) {
                $addIssue('battery_low', 'warning', 'Terminal battery is low.', 15);
            }
        }

        if ($this->metaValue('build_mode') === 'debug') {
            $addIssue('debug_build', 'warning', 'Terminal is running a debug build.', 10);
        }

        if ($minAppVersion !== null && $appVersion !== null && self::compareVersions((string) $appVersion, $minAppVersion) < 0) {
            $addIssue('upgrade_required', 'warning', 'Terminal app version is below the configured minimum.', 15);
        }

        $score = max(0, min(100, $score));
        $severities = array_column($issues, 'severity');
        $healthStatus = in_array('critical', $severities, true) || $score < 60
            ? 'critical'
            : (in_array('warning', $severities, true) || $score < 90 ? 'warning' : 'ok');
        $primaryIssue = $issues[0]['code'] ?? null;

        return [
            'score' => $score,
            'status' => $healthStatus,
            'issues' => $issues,
            'recommended_action' => $this->recommendedAction($primaryIssue),
        ];
    }

    private function recommendedAction(?string $issueCode): ?string
    {
        return match ($issueCode) {
            'disabled' => 'Enable the terminal only after confirming the device is trusted.',
            'offline' => 'Check tablet power, WiFi, and whether the POS app is open.',
            'error' => 'Open the terminal device details and inspect the latest error or queue state.',
            'syncing', 'queue_backlog', 'queue_pending' => 'Keep the app online until queued work is synced.',
            'crashes' => 'Capture crash details and update/restart the POS app before the next rush.',
            'battery_low' => 'Connect the terminal to power.',
            'debug_build' => 'Install a release build on this terminal.',
            'upgrade_required' => 'Upgrade the terminal app to the configured minimum version.',
            default => null,
        };
    }

    private function metaValue(string $key): mixed
    {
        $meta = $this->meta;

        if (! is_array($meta)) {
            return null;
        }

        return $meta[$key] ?? null;
    }

    private function loadedAttribute(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, $this->getAttributes())) {
            return $default;
        }

        return $this->getAttributeValue($key);
    }
}
