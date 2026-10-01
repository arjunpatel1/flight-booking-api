<?php

namespace Modules\Notification\Services\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Pos\Models\PosTerminalDevice;
use Modules\User\Models\CustomerPushDevice;
use Modules\User\Models\User;
use RuntimeException;

class PushChannel implements NotificationChannelInterface
{
    public function send(string $recipient, array $payload): array
    {
        abort_unless(
            (bool) setting('notifications_push_enabled', false),
            503,
            'Push notifications are disabled.'
        );

        $credentials = $this->credentials();
        $devices = $this->recipientDevices($recipient, $payload);

        if ($devices->isEmpty()) {
            throw new RuntimeException('No registered push device is available for this recipient.');
        }

        $accessToken = $this->accessToken($credentials);
        $projectId = $credentials['project_id'] ?? config('services.firebase.project_id');
        $sent = 0;
        $failed = 0;

        foreach ($devices as $device) {
            try {
                Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(12)
                    ->retry(2, 250)
                    ->post(
                        "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
                        ['message' => $this->message($device, $payload)]
                    )
                    ->throw();
                $sent++;
            } catch (RequestException $exception) {
                $failed++;
                $errorCode = data_get($exception->response?->json(), 'error.details.0.errorCode');
                if ($errorCode === 'UNREGISTERED') {
                    $this->invalidateDevice($device);
                }
            }
        }

        if ($sent === 0) {
            throw new RuntimeException('Firebase rejected every registered device.');
        }

        return [
            'queued' => false,
            'channel' => 'push',
            'recipient' => $recipient,
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    private function recipientDevices(string $recipient, array $payload)
    {
        $query = PosTerminalDevice::withoutGlobalScopes()
            ->whereNotNull('push_token')
            ->where('is_disabled', false);

        $tenantRecipient = str_starts_with($recipient, 'tenant:');
        if ($tenantRecipient) {
            $tenantId = (int) str($recipient)->after('tenant:')->toString();
        }

        if ($tenantRecipient && $tenantId) {
            $posDevices = $query
                ->whereHas('branch', fn ($branch) => $branch->where('tenant_id', $tenantId))
                ->get(['id', 'push_token']);

            if (! (bool) data_get($payload, 'include_customers', false)) {
                return $posDevices;
            }

            return $posDevices->concat(
                CustomerPushDevice::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('revoked_at')
                    ->whereNotNull('push_token')
                    ->get(['id', 'push_token'])
            );
        }

        $userIds = User::withoutGlobalScopes()
            ->when(
                filled(data_get($payload, 'tenant_id')),
                fn ($users) => $users->where('tenant_id', (int) data_get($payload, 'tenant_id')),
            )
            ->where(function ($users) use ($recipient): void {
                if (ctype_digit($recipient)) {
                    $users->whereKey((int) $recipient);
                } else {
                    $users->where('email', $recipient);
                }
            })
            ->pluck('id');

        $posDevices = $query
            ->whereIn('created_by', $userIds)
            ->get(['id', 'push_token']);

        return $posDevices->concat(
            CustomerPushDevice::query()
                ->whereIn('user_id', $userIds)
                ->when(
                    filled(data_get($payload, 'tenant_id')),
                    fn ($devices) => $devices->where('tenant_id', (int) data_get($payload, 'tenant_id')),
                )
                ->whereNull('revoked_at')
                ->whereNotNull('push_token')
                ->get(['id', 'push_token'])
        );
    }

    private function invalidateDevice(Model $device): void
    {
        if ($device instanceof CustomerPushDevice) {
            $device->forceFill(['revoked_at' => now()])->saveQuietly();
            return;
        }

        $device->forceFill(['push_token' => null])->saveQuietly();
    }

    private function credentials(): array
    {
        $value = setting('firebase_service_account_json')
            ?: config('services.firebase.credentials');

        if (is_string($value) && is_file($value)) {
            $value = file_get_contents($value);
        }

        $credentials = is_array($value) ? $value : json_decode((string) $value, true);
        if (! is_array($credentials)
            || blank($credentials['client_email'] ?? null)
            || blank($credentials['private_key'] ?? null)
            || blank($credentials['project_id'] ?? config('services.firebase.project_id'))) {
            throw new RuntimeException('Firebase service account is not configured.');
        }

        return $credentials;
    }

    private function accessToken(array $credentials): string
    {
        $cacheKey = 'firebase:oauth:'.hash('sha256', $credentials['client_email']);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($credentials): string {
            $now = time();
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

            $unsigned = "{$header}.{$claims}";
            if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Firebase service-account signing failed.');
            }

            $response = Http::asForm()
                ->timeout(12)
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => "{$unsigned}.{$this->base64Url($signature)}",
                ])
                ->throw()
                ->json();

            return $response['access_token']
                ?? throw new RuntimeException('Firebase access token was not returned.');
        });
    }

    private function message(Model $device, array $payload): array
    {
        $title = (string) ($payload['title'] ?? 'NexDine');
        $body = (string) ($payload['message'] ?? $payload['body'] ?? '');
        $data = collect($payload)
            ->mapWithKeys(fn ($value, $key) => [
                (string) $key => is_scalar($value) || $value === null
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ])
            ->all();

        return [
            'token' => (string) $device->push_token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => $data,
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'channel_id' => $device instanceof CustomerPushDevice
                        ? 'nexdine_customer_channel'
                        : 'nexdine_pos_channel',
                ],
            ],
        ];
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
