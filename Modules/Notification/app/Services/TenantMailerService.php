<?php

namespace Modules\Notification\Services;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Modules\Setting\Models\Setting;

/**
 * A tenant's own outgoing mail server (e.g. their Gmail account).
 *
 * The platform mailer is configured once at boot from global settings, so it
 * can never carry a tenant's credentials. Each tenant's SMTP details are kept
 * in that tenant's settings scope instead and turned into a dedicated mailer
 * only when that tenant sends mail. Tenants without their own server keep
 * using the platform mailer.
 */
class TenantMailerService
{
    public const KEYS = [
        'tenant_smtp_enabled',
        'tenant_smtp_host',
        'tenant_smtp_port',
        'tenant_smtp_username',
        'tenant_smtp_password',
        'tenant_smtp_encryption',
        'tenant_smtp_from_address',
        'tenant_smtp_from_name',
    ];

    private const ENCRYPTED_KEYS = ['tenant_smtp_password'];

    /**
     * Read the tenant's saved mail server settings, including the password.
     */
    public function settings(int $tenantId): array
    {
        // Read the tenant scope directly: queued jobs run without an
        // authenticated user, so the request-bound setting() helper would
        // otherwise fall back to the platform's values.
        $rows = Setting::query()
            ->where('setting_scope', $this->scope($tenantId))
            ->whereIn('key', self::KEYS)
            ->get()
            ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->payload]);

        return [
            'enabled' => (bool) ($rows['tenant_smtp_enabled'] ?? false),
            'host' => (string) ($rows['tenant_smtp_host'] ?? ''),
            'port' => (int) ($rows['tenant_smtp_port'] ?? 587),
            'username' => (string) ($rows['tenant_smtp_username'] ?? ''),
            'password' => (string) ($rows['tenant_smtp_password'] ?? ''),
            'encryption' => $rows['tenant_smtp_encryption'] ?? 'tls',
            'from_address' => (string) ($rows['tenant_smtp_from_address'] ?? ''),
            'from_name' => (string) ($rows['tenant_smtp_from_name'] ?? ''),
        ];
    }

    /**
     * Persist the tenant's mail server. A blank password keeps the stored one.
     */
    public function save(int $tenantId, array $data): void
    {
        $values = [
            'tenant_smtp_enabled' => (bool) $data['enabled'],
            'tenant_smtp_host' => $data['host'] ?? null,
            'tenant_smtp_port' => isset($data['port']) ? (int) $data['port'] : null,
            'tenant_smtp_username' => $data['username'] ?? null,
            'tenant_smtp_encryption' => $data['encryption'] ?? null,
            'tenant_smtp_from_address' => $data['from_address'] ?? null,
            'tenant_smtp_from_name' => $data['from_name'] ?? null,
        ];

        if (filled($data['password'] ?? null)) {
            $values['tenant_smtp_password'] = $data['password'];
        }

        foreach ($values as $key => $payload) {
            Setting::query()->updateOrCreate(
                ['setting_scope' => $this->scope($tenantId), 'key' => $key],
                [
                    'tenant_id' => $tenantId,
                    'branch_id' => null,
                    // Must be filled before payload: the payload mutator
                    // decides between encrypt() and serialize() from it.
                    'is_encryptable' => in_array($key, self::ENCRYPTED_KEYS, true),
                    'payload' => $payload,
                ],
            );
        }

        Mail::purge($this->mailerName($tenantId));
    }

    /**
     * The address the tenant's mail goes out from when their own server is on.
     */
    public function senderAddress(int $tenantId): ?string
    {
        $settings = $this->settings($tenantId);

        return $settings['enabled'] && filled($settings['host']) && filled($settings['from_address'])
            ? $settings['from_address']
            : null;
    }

    public function hasPassword(int $tenantId): bool
    {
        return filled($this->settings($tenantId)['password']);
    }

    /**
     * The tenant's own mailer and sender, or null to use the platform mailer.
     *
     * @return array{mailer: Mailer, from_address: string, from_name: string}|null
     */
    public function resolve(int $tenantId): ?array
    {
        $settings = $this->settings($tenantId);

        if (! $settings['enabled'] || blank($settings['host']) || blank($settings['from_address'])) {
            return null;
        }

        return $this->build($tenantId, $settings);
    }

    /**
     * Build a mailer from the given settings, whether or not they are enabled.
     * Used by the connection test before the tenant switches it on.
     *
     * @return array{mailer: Mailer, from_address: string, from_name: string}
     */
    public function build(int $tenantId, array $settings): array
    {
        $name = $this->mailerName($tenantId);

        config(["mail.mailers.{$name}" => [
            'transport' => 'smtp',
            'host' => $settings['host'],
            'port' => (int) $settings['port'],
            // Symfony Mailer: 'ssl' means implicit TLS (465); 'tls' upgrades via STARTTLS (587).
            'scheme' => $settings['encryption'] === 'ssl' ? 'smtps' : 'smtp',
            'username' => $settings['username'] ?: null,
            'password' => $settings['password'] ?: null,
            'timeout' => 20,
        ]]);

        // A long-running queue worker keeps resolved mailers; drop the cached
        // one so changed credentials take effect on the next message.
        Mail::purge($name);

        return [
            'mailer' => Mail::mailer($name),
            'from_address' => $settings['from_address'],
            // Blank lets each caller fall back to its own brand name.
            'from_name' => (string) $settings['from_name'],
        ];
    }

    private function scope(int $tenantId): string
    {
        return "tenant:{$tenantId}";
    }

    private function mailerName(int $tenantId): string
    {
        return "tenant_smtp_{$tenantId}";
    }
}
