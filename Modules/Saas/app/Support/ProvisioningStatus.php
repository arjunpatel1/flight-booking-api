<?php

namespace Modules\Saas\Support;

final class ProvisioningStatus
{
    public const PENDING = 'PENDING';
    public const VALIDATING = 'VALIDATING';
    public const DATABASE_READY = 'DATABASE_READY';
    public const TENANT_CREATED = 'TENANT_CREATED';
    public const BRANCH_CREATED = 'BRANCH_CREATED';
    public const ADMIN_CREATED = 'ADMIN_CREATED';
    public const SUBSCRIPTION_CREATED = 'SUBSCRIPTION_CREATED';
    public const SETTINGS_READY = 'SETTINGS_READY';
    public const STORAGE_READY = 'STORAGE_READY';
    public const CLIENT_CONFIG_READY = 'CLIENT_CONFIG_READY';
    public const QR_READY = 'QR_READY';
    public const WAITER_READY = 'WAITER_READY';
    public const SERVER_AUTOMATION_RUNNING = 'SERVER_AUTOMATION_RUNNING';
    public const EMAIL_SENT = 'EMAIL_SENT';
    public const CACHE_WARMED = 'CACHE_WARMED';
    public const HEALTH_VERIFIED = 'HEALTH_VERIFIED';
    public const COMPLETED = 'COMPLETED';
    public const FAILED = 'FAILED';
    public const PARTIALLY_COMPLETED = 'PARTIALLY_COMPLETED';
    public const CANCELLED = 'CANCELLED';

    public static function terminal(string $status): bool
    {
        return in_array($status, [self::COMPLETED, self::FAILED, self::PARTIALLY_COMPLETED, self::CANCELLED], true);
    }
}
