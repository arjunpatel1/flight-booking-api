<?php

namespace Modules\WhatsAppCenter\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Verifies a NexMsg account against the values an administrator entered.
 *
 * report() returns an itemised, secret-free checklist for setup screens;
 * validate() turns the same checks into field errors for save requests.
 */
final class NexMsgConnectionValidator
{
    public const DEFAULT_WEBHOOK_URL = 'https://api.nexdine.myteknoland.in/v1/whatsapp/webhook/nexmsg';

    public function validate(array $data): void
    {
        if (($data['provider'] ?? null) !== 'nexmsg') {
            return;
        }

        $report = $this->report($data);
        if ($report['reachable'] === false) {
            abort(503, 'NexMsg could not be reached. The existing WhatsApp connection was kept unchanged; try again shortly.');
        }

        $errors = [];
        foreach ($report['checks'] as $check) {
            if ($check['status'] === 'fail' && $check['field'] !== null) {
                $errors[$check['field']] ??= $check['message'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array{reachable: bool, ready: bool, checks: list<array{key: string, label: string, status: string, message: string, field: ?string}>}
     */
    public function report(array $data): array
    {
        $credentials = (array) ($data['credentials'] ?? []);
        $accountId = trim((string) ($credentials['account_id'] ?? ''));
        $authKey = trim((string) ($credentials['auth_key'] ?? ''));

        try {
            $response = Http::connectTimeout(5)->timeout(12)
                ->withHeaders(['authkey' => $authKey, 'Accept' => 'application/json'])
                ->get('https://api-nexmsg.myteknoland.com/api/accounts');
        } catch (Throwable) {
            return $this->result(false, [$this->check('provider_reachable', 'NexMsg reachable', 'fail',
                'NexMsg could not be reached. Try again shortly.', null)]);
        }

        if ($response->failed()) {
            return $this->result(true, [$this->check('auth_key', 'NexMsg Auth Key', 'fail', $response->status() === 401
                ? 'NexMsg rejected this Auth Key. The existing WhatsApp connection was kept unchanged.'
                : 'NexMsg could not verify this account. The existing WhatsApp connection was kept unchanged.',
                'credentials.account_id')]);
        }

        $account = collect($response->json())->first(
            fn ($row) => is_array($row) && $accountId !== '' && hash_equals((string) ($row['id'] ?? ''), $accountId)
        );
        $checks = [$this->check('auth_key', 'NexMsg Auth Key', 'pass', 'NexMsg accepted the Auth Key.', null)];

        if (! $account) {
            $checks[] = $this->check('account_id', 'NexMsg Account ID', 'fail',
                'This Account ID does not belong to the supplied NexMsg Auth Key. Copy the 24-character Account ID from NexMsg; do not use the WABA ID.',
                'credentials.account_id');

            return $this->result(true, $checks);
        }

        $checks[] = $this->check('account_id', 'NexMsg Account ID', 'pass', 'The account belongs to this Auth Key.', null);
        $checks[] = $this->digits($data['provider_phone_id'] ?? null) === $this->digits($account['wabaId'] ?? null)
            ? $this->check('waba_id', 'WABA ID', 'pass', 'The WABA ID matches the NexMsg account.', null)
            : $this->check('waba_id', 'WABA ID', 'fail', 'The WABA ID does not match this NexMsg account.', 'provider_phone_id');
        $checks[] = $this->digits($data['display_number'] ?? null) === $this->digits($account['displayPhone'] ?? null)
            ? $this->check('display_number', 'Display number', 'pass', 'The display number matches the NexMsg account.', null)
            : $this->check('display_number', 'Display number', 'fail', 'The display number does not match this NexMsg account.', 'display_number');

        $catalogId = trim((string) ($credentials['catalog_id'] ?? ''));
        $providerCatalog = trim((string) ($account['catalogId'] ?? ''));
        $checks[] = $catalogId !== '' && $providerCatalog !== '' && hash_equals($catalogId, $providerCatalog)
            ? $this->check('catalog_id', 'Meta Catalog ID', 'pass', 'The same catalog is assigned in NexMsg.', null)
            : $this->check('catalog_id', 'Meta Catalog ID', 'fail', $providerCatalog === ''
                ? 'Assign this Meta Catalog ID to the NexMsg account before connecting. NexMsg has no catalog on this account yet.'
                : 'Assign this Meta Catalog ID to the NexMsg account before connecting. NexMsg uses a different catalog on this account.',
                'credentials.catalog_id');

        $ordering = (array) ($account['nexdineOrdering'] ?? []);
        $webhookMessage = 'Enable NexDine Ordering in NexMsg, use the trusted NexDine webhook URL, and save the same webhook secret before connecting.';
        $checks[] = ($ordering['enabled'] ?? false) === true
            ? $this->check('ordering_enabled', 'NexDine Ordering enabled in NexMsg', 'pass', 'NexMsg forwards customer messages to NexDine.', null)
            : $this->check('ordering_enabled', 'NexDine Ordering enabled in NexMsg', 'fail', $webhookMessage, 'credentials.webhook_secret');
        $checks[] = hash_equals($this->webhookUrl(), trim((string) ($ordering['webhookUrl'] ?? '')))
            ? $this->check('webhook_url', 'Webhook URL in NexMsg', 'pass', 'NexMsg uses the trusted NexDine webhook URL.', null)
            : $this->check('webhook_url', 'Webhook URL in NexMsg', 'fail', "Set the NexMsg webhook URL to {$this->webhookUrl()}.", 'credentials.webhook_secret');
        $checks[] = ($ordering['secretConfigured'] ?? false) === true
            ? $this->check('webhook_secret', 'Webhook secret in NexMsg', 'pass', 'A webhook secret is saved in NexMsg.', null)
            : $this->check('webhook_secret', 'Webhook secret in NexMsg', 'fail', 'Save the same webhook secret in NexMsg.', 'credentials.webhook_secret');

        $providerError = trim((string) ($ordering['lastError'] ?? ''));
        if ($providerError !== '') {
            $checks[] = $this->check('provider_delivery', 'Last NexMsg delivery', 'warn',
                'NexMsg reported: '.mb_substr(preg_replace('/\s+/', ' ', $providerError) ?: '', 0, 200), null);
        }

        return $this->result(true, $checks);
    }

    public function webhookUrl(): string
    {
        return (string) config('whatsappcenter.nexmsg.trusted_webhook_url', self::DEFAULT_WEBHOOK_URL);
    }

    private function result(bool $reachable, array $checks): array
    {
        return [
            'reachable' => $reachable,
            'ready' => $reachable && collect($checks)->every(fn ($check) => $check['status'] !== 'fail'),
            'checks' => $checks,
        ];
    }

    private function check(string $key, string $label, string $status, string $message, ?string $field): array
    {
        return compact('key', 'label', 'status', 'message', 'field');
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?: '';
    }
}
