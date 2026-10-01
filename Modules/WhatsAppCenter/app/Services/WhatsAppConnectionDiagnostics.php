<?php

namespace Modules\WhatsAppCenter\Services;

use Modules\Branch\Models\Branch;
use Modules\Saas\Models\Tenant;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;

/**
 * One secret-free, ordered checklist that explains whether a WhatsApp number
 * can receive "Hi" and reply. Used by the platform setup screen and the
 * restaurant's WhatsApp Catalog page.
 */
final class WhatsAppConnectionDiagnostics
{
    private const BLOCKED_STATUSES = ['disabled', 'suspended', 'revoked'];

    public function __construct(private readonly NexMsgConnectionValidator $nexMsg) {}

    /**
     * @return array{ready: bool, provider: string, checked_at: string, webhook_url: ?string, checks: list<array{key: string, label: string, status: string, message: string}>}
     */
    public function forProfile(WhatsAppProviderProfile $profile, bool $persist = true): array
    {
        $profile->loadMissing('phoneNumbers');
        $number = $profile->phoneNumbers->firstWhere('is_active', true) ?? $profile->phoneNumbers->first();
        $credentials = is_array($profile->credentials) ? $profile->credentials : [];
        $checks = [];

        $checks[] = $profile->is_active && ! in_array($profile->status, self::BLOCKED_STATUSES, true)
            ? $this->check('profile_active', 'Provider profile active', 'pass', 'The provider profile is active.')
            : $this->check('profile_active', 'Provider profile active', 'fail', 'The provider profile is disabled. Activate it before going live.');

        $checks[] = $number && $number->is_active
            ? $this->check('number_active', 'WhatsApp number active', 'pass', "{$number->display_number} is active.")
            : $this->check('number_active', 'WhatsApp number active', 'fail', 'Add and activate the WhatsApp number on this profile.');

        if ($number) {
            $duplicates = WhatsAppPhoneNumber::query()
                ->where('provider_phone_id', $number->provider_phone_id)->where('is_active', true)
                ->whereKeyNot($number->id)
                ->whereHas('profile', fn ($query) => $query->withoutGlobalTenant()
                    ->where('provider', $profile->provider)->where('is_active', true)
                    ->whereNotIn('status', self::BLOCKED_STATUSES))
                ->exists();
            $checks[] = $duplicates
                ? $this->check('number_unique', 'Number used once', 'fail', 'Another active profile uses the same provider number ID, so incoming messages cannot be routed. Disable the duplicate profile.')
                : $this->check('number_unique', 'Number used once', 'pass', 'Incoming messages route to exactly one profile.');
        }

        $checks[] = strlen((string) ($credentials['webhook_secret'] ?? '')) >= 16
            ? $this->check('secret_saved', 'Webhook secret saved in NexDine', 'pass', 'A webhook secret is stored (encrypted).')
            : $this->check('secret_saved', 'Webhook secret saved in NexDine', 'fail', 'Save a webhook secret of at least 16 characters.');

        $checks = [...$checks, ...$this->assignmentChecks($profile, $number)];

        if ($profile->provider === 'nexmsg' && $number) {
            $report = $this->nexMsg->report([
                'provider' => 'nexmsg',
                'provider_phone_id' => $number->provider_phone_id,
                'display_number' => $number->display_number,
                'credentials' => $credentials,
            ]);
            foreach ($report['checks'] as $check) {
                $checks[] = $this->check('nexmsg_'.$check['key'], $check['label'], $check['status'], $check['message']);
            }
        } else {
            $checks[] = $this->check('provider_portal', 'Provider portal configuration', 'warn',
                'This provider cannot be verified automatically. Confirm the webhook URL, secret and message subscription in the provider portal.');
        }

        $checks[] = $profile->webhook_last_received_at
            ? $this->check('first_message', 'Customer message received', 'pass', 'Last message received '.$profile->webhook_last_received_at->diffForHumans().'.')
            : $this->check('first_message', 'Customer message received', 'warn', 'No customer message has reached NexDine yet. After every check above passes, send "Hi" from another phone.');

        if (filled($profile->last_error)) {
            $checks[] = $this->check('last_rejection', 'Last rejected webhook', 'warn', (string) $profile->last_error);
        }

        $ready = collect($checks)->every(fn ($check) => $check['status'] !== 'fail');
        if ($persist) {
            $this->persist($profile, $ready, $checks);
        }

        return [
            'ready' => $ready,
            'provider' => (string) $profile->provider,
            'checked_at' => now()->toIso8601String(),
            'webhook_url' => $profile->provider === 'nexmsg' ? $this->nexMsg->webhookUrl() : null,
            'checks' => $checks,
        ];
    }

    private function assignmentChecks(WhatsAppProviderProfile $profile, ?WhatsAppPhoneNumber $number): array
    {
        $assignment = $number ? WhatsAppTenantAssignment::query()->withoutGlobalTenant()
            ->where('provider_profile_id', $profile->id)->where('phone_number_id', $number->id)
            ->latest('is_active')->latest('id')->first() : null;

        if (! $assignment) {
            return [$this->check('assignment', 'Assigned to a restaurant', 'fail', 'Assign this number to a restaurant. Unassigned numbers reject every customer message.')];
        }

        $tenant = Tenant::query()->withoutGlobalScopes()->find($assignment->tenant_id);
        $name = $tenant?->name ?: 'the restaurant';
        $checks = [];
        $checks[] = $assignment->is_active && $assignment->suspended_at === null
            ? $this->check('assignment', 'Assigned to a restaurant', 'pass', "Assigned to {$name}.")
            : $this->check('assignment', 'Assigned to a restaurant', 'fail', "The assignment to {$name} is inactive, so customer messages are rejected. Activate it.");
        $checks[] = $tenant?->is_active
            ? $this->check('tenant_active', 'Restaurant active', 'pass', "{$name} is active.")
            : $this->check('tenant_active', 'Restaurant active', 'fail', "{$name} is inactive.");

        $capabilities = (array) ($assignment->capabilities ?? []);
        $ordering = data_get($capabilities, 'ordering') === true || in_array('ordering', $capabilities, true);
        $checks[] = $ordering
            ? $this->check('ordering_enabled', 'WhatsApp ordering switched on', 'pass', 'Ordering is enabled for this restaurant.')
            : $this->check('ordering_enabled', 'WhatsApp ordering switched on', 'fail', 'Turn on WhatsApp ordering on the restaurant assignment.');

        $configured = collect($assignment->allowed_branch_ids ?? [])->map(fn ($id) => (int) $id)->filter()->unique();
        $active = Branch::query()->withoutGlobalActive()->where('tenant_id', $assignment->tenant_id)->where('is_active', true)
            ->when($configured->isNotEmpty(), fn ($query) => $query->whereIn('id', $configured))->count();
        $checks[] = $active > 0 && ($configured->isEmpty() || $active === $configured->count())
            ? $this->check('branches', 'Ordering branches active', 'pass', $configured->isEmpty() ? 'All active branches accept WhatsApp orders.' : "{$active} selected branch(es) accept WhatsApp orders.")
            : $this->check('branches', 'Ordering branches active', 'fail', 'One or more selected branches are inactive or missing. Update the allowed branches.');

        return $checks;
    }

    private function persist(WhatsAppProviderProfile $profile, bool $ready, array $checks): void
    {
        $firstFailure = collect($checks)->firstWhere('status', 'fail');
        $changes = ['validated_at' => now()];
        if (! in_array($profile->status, self::BLOCKED_STATUSES, true)) {
            $changes['status'] = ! $ready ? 'failed' : ($profile->webhook_last_received_at ? 'connected' : 'pending');
        }
        if ($firstFailure) {
            $changes['last_error'] = $firstFailure['label'].': '.$firstFailure['message'];
        } elseif ($ready) {
            $changes['last_error'] = null;
        }
        $profile->forceFill($changes)->saveQuietly();
    }

    private function check(string $key, string $label, string $status, string $message): array
    {
        return compact('key', 'label', 'status', 'message');
    }
}
