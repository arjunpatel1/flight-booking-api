<?php

namespace Modules\WhatsAppCenter\Services\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\WhatsAppCenter\Contracts\SendsWhatsAppCatalog;
use Modules\WhatsAppCenter\Data\NormalizedWebhookMessage;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use RuntimeException;

class NexMsgOrderingProvider extends AbstractWhatsAppOrderingProvider implements SendsWhatsAppCatalog
{
    private const PAYMENT_BUTTON_TEMPLATES = ['nexdine_order_payment_cancel_chat_v3', 'nexdine_order_payment_v2'];

    private const CANCEL_BUTTON_TEMPLATES = ['nexdine_order_received_actions_v2'];

    public function sendOrderTypeChoices(WhatsAppProviderProfile $profile, WhatsAppPhoneNumber $phoneNumber, string $recipient, string $body, array $choices): array
    {
        $authKey = trim((string) data_get($profile->credentials, 'auth_key'));
        $wabaId = preg_replace('/\D+/', '', (string) $phoneNumber->provider_phone_id);
        $to = $this->normalizePhone($recipient);
        $choices = array_values(array_intersect(['delivery', 'pickup'], array_unique($choices)));
        abort_unless($authKey !== '' && preg_match('/^\d{6,32}$/', $wabaId)
            && preg_match('/^\d{10,15}$/', $to) && $choices !== [], 422, 'NexMsg order choice configuration is invalid.');
        $response = Http::connectTimeout(5)->timeout(15)->withHeaders(['authkey' => $authKey, 'Accept' => 'application/json'])
            ->asJson()->post((string) config('whatsappcenter.nexmsg.order_type_send_url'), [
                'wabaId' => $wabaId, 'to' => $to, 'bodyText' => mb_substr(trim($body), 0, 1024), 'choices' => $choices,
            ]);
        if ($response->failed() || data_get($response->json(), 'success') !== true || ! filled(data_get($response->json(), 'messageId'))) {
            throw new RuntimeException("NexMsg rejected the order choice message with status {$response->status()}.");
        }

        return ['status' => $response->status(), 'provider_message_id' => data_get($response->json(), 'messageId')];
    }

    public function sendLocationRequest(WhatsAppProviderProfile $profile, WhatsAppPhoneNumber $phoneNumber, string $recipient, string $body): array
    {
        $authKey = trim((string) data_get($profile->credentials, 'auth_key'));
        $wabaId = preg_replace('/\D+/', '', (string) $phoneNumber->provider_phone_id);
        $to = $this->normalizePhone($recipient);
        abort_unless($authKey !== '' && preg_match('/^\d{6,32}$/', $wabaId) && preg_match('/^\d{10,15}$/', $to), 422, 'NexMsg location request configuration is invalid.');
        $response = Http::connectTimeout(5)->timeout(15)->withHeaders(['authkey' => $authKey, 'Accept' => 'application/json'])
            ->asJson()->post((string) config('whatsappcenter.nexmsg.location_request_send_url'), [
                'wabaId' => $wabaId, 'to' => $to, 'bodyText' => mb_substr(trim($body), 0, 1024),
            ]);
        if ($response->failed() || data_get($response->json(), 'success') !== true || ! filled(data_get($response->json(), 'messageId'))) {
            throw new RuntimeException("NexMsg rejected the location request with status {$response->status()}.");
        }

        return ['status' => $response->status(), 'provider_message_id' => data_get($response->json(), 'messageId')];
    }

    public function sendAddressActions(WhatsAppProviderProfile $profile, WhatsAppPhoneNumber $phoneNumber, string $recipient, string $body, array $actions): array
    {
        $authKey = trim((string) data_get($profile->credentials, 'auth_key'));
        $wabaId = preg_replace('/\D+/', '', (string) $phoneNumber->provider_phone_id);
        $to = $this->normalizePhone($recipient);
        $actions = array_values(array_intersect(['confirm', 'change', 'skip'], array_unique($actions)));
        abort_unless($authKey !== '' && preg_match('/^\d{6,32}$/', $wabaId) && preg_match('/^\d{10,15}$/', $to) && $actions !== [], 422, 'NexMsg address action configuration is invalid.');
        $response = Http::connectTimeout(5)->timeout(15)->withHeaders(['authkey' => $authKey, 'Accept' => 'application/json'])
            ->asJson()->post((string) config('whatsappcenter.nexmsg.address_actions_send_url'), [
                'wabaId' => $wabaId, 'to' => $to, 'bodyText' => mb_substr(trim($body), 0, 1024), 'actions' => $actions,
            ]);
        if ($response->failed() || data_get($response->json(), 'success') !== true || ! filled(data_get($response->json(), 'messageId'))) {
            throw new RuntimeException("NexMsg rejected the address actions with status {$response->status()}.");
        }

        return ['status' => $response->status(), 'provider_message_id' => data_get($response->json(), 'messageId')];
    }

    public function sendOrderReceiptButton(WhatsAppProviderProfile $profile, WhatsAppPhoneNumber $phoneNumber, string $recipient, array $details, string $cancelToken): ?array
    {
        $credentials = $profile->credentials ?? [];
        $authKey = trim((string) ($credentials['auth_key'] ?? ''));
        $accountId = trim((string) ($credentials['account_id'] ?? ''));
        $to = $this->normalizePhone($recipient);
        if ($authKey === '' || $accountId === '' || ! preg_match('/^\d{10,15}$/', $to)) {
            return null;
        }

        // NexMsg charges approved template sends against the number's own
        // prepaid balance. The user wallet and AC balance are separate pools;
        // a positive value there does not make this send deliverable.
        $prepaid = Cache::remember('whatsapp:order-button-prepaid:'.$accountId, now()->addMinute(), function () use ($authKey, $accountId): ?float {
            $response = Http::connectTimeout(5)->timeout(10)->withHeaders(['authkey' => $authKey])
                ->get('https://api-nexmsg.myteknoland.com/api/accounts');
            if ($response->failed()) {
                return null;
            }
            $account = collect($response->json())->first(fn ($row) => is_array($row)
                && (string) ($row['id'] ?? '') === $accountId);

            return is_numeric($account['prepaidBalance'] ?? null)
                ? (float) $account['prepaidBalance'] : null;
        });
        if ($prepaid === null || $prepaid <= 0) {
            return null;
        }

        // The gateway only sends URL buttons through approved templates.
        // Cache the provider status briefly so approval takes effect without a deploy.
        $paymentRequired = (bool) ($details['payment_required'] ?? false);
        $paymentToken = trim((string) ($details['payment_token'] ?? ''));
        $candidates = $paymentRequired ? self::PAYMENT_BUTTON_TEMPLATES : self::CANCEL_BUTTON_TEMPLATES;
        if ($paymentRequired && $paymentToken === '') {
            return null;
        }
        $templateName = Cache::remember('whatsapp:order-button-template:'.$accountId.':'.($paymentRequired ? 'payment' : 'cancel'), now()->addMinute(), function () use ($authKey, $accountId, $candidates): ?string {
            $response = Http::connectTimeout(5)->timeout(10)->withHeaders(['authkey' => $authKey])
                ->get((string) config('whatsappcenter.nexmsg.templates_list_url'));
            if ($response->failed()) {
                return null;
            }
            $templates = collect($response->json())->filter(fn ($row) => is_array($row)
                && in_array($row['name'] ?? null, $candidates, true)
                && (string) ($row['accountId'] ?? '') === $accountId);
            foreach ($candidates as $name) {
                if ($templates->contains(fn ($row) => ($row['name'] ?? null) === $name
                    && strtoupper((string) ($row['status'] ?? '')) === 'APPROVED')) {
                    return $name;
                }
            }

            return null;
        });
        if (! $templateName) {
            return null;
        }

        $components = [[
            'type' => 'body',
            'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => (string) $value], [
                $details['customer_name'] ?? 'Customer',
                $details['order_reference'] ?? '',
                $details['restaurant_name'] ?? 'Restaurant',
                $details['total'] ?? '',
            ]),
        ]];
        if ($paymentRequired) {
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $paymentToken]]];
            if ($templateName === 'nexdine_order_payment_cancel_chat_v3') {
                $components[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1',
                    'parameters' => [['type' => 'payload', 'payload' => 'cancel_order:'.$cancelToken]]];
            }
        } else {
            // Cancellation stays inside WhatsApp. The quick reply carries an
            // opaque token; the inbound handler then asks for confirmation and
            // locks/rechecks kitchen state before it changes the order.
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0',
                'parameters' => [['type' => 'text', 'text' => (string) ($details['order_reference'] ?? '')]]];
            $components[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1',
                'parameters' => [['type' => 'payload', 'payload' => 'cancel_order:'.$cancelToken]]];
        }
        $response = Http::connectTimeout(5)->timeout(15)
            ->withHeaders(['authkey' => $authKey])->asJson()
            ->post((string) config('whatsappcenter.nexmsg.template_send_url'), [
                'accountId' => $accountId,
                'templateName' => $templateName,
                'to' => $to,
                'languageCode' => 'en',
                'components' => $components,
            ]);
        if ($response->failed() || data_get($response->json(), 'success') === false) {
            throw new RuntimeException("NexMsg rejected the order button template with status {$response->status()}.");
        }

        $messageId = data_get($response->json(), 'messageId')
            ?: data_get($response->json(), 'message_id')
            ?: data_get($response->json(), 'results.0.messageId');
        if (! filled($messageId)) {
            throw new RuntimeException('NexMsg did not confirm a provider message ID for the order button.');
        }

        // Until Meta approves the combined Pay Now + Cancel quick-reply
        // template, the approved payment-only fallback is followed by the
        // approved Track Order + Cancel template. This keeps both customer
        // actions available without falling back to a browser cancellation URL.
        if ($paymentRequired && $templateName !== 'nexdine_order_payment_cancel_chat_v3') {
            $this->sendCompanionCancelAction(
                $authKey, $accountId, $to, $details, $cancelToken,
            );
        }

        return [
            'status' => $response->status(),
            'provider_message_id' => $messageId,
        ];
    }

    private function sendCompanionCancelAction(
        string $authKey,
        string $accountId,
        string $to,
        array $details,
        string $cancelToken,
    ): void {
        $templateName = Cache::remember(
            'whatsapp:order-button-template:'.$accountId.':payment-cancel-companion',
            now()->addMinute(),
            function () use ($authKey, $accountId): ?string {
                $response = Http::connectTimeout(5)->timeout(10)
                    ->withHeaders(['authkey' => $authKey])
                    ->get((string) config('whatsappcenter.nexmsg.templates_list_url'));
                if ($response->failed()) return null;

                $approved = collect($response->json())->contains(fn ($row) => is_array($row)
                    && ($row['name'] ?? null) === 'nexdine_order_received_actions_v2'
                    && (string) ($row['accountId'] ?? '') === $accountId
                    && strtoupper((string) ($row['status'] ?? '')) === 'APPROVED');

                return $approved ? 'nexdine_order_received_actions_v2' : null;
            },
        );
        if (! $templateName) return;

        $body = [[
            'type' => 'body',
            'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => (string) $value], [
                $details['customer_name'] ?? 'Customer',
                $details['order_reference'] ?? '',
                $details['restaurant_name'] ?? 'Restaurant',
                $details['total'] ?? '',
            ]),
        ]];
        $body[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0',
            'parameters' => [['type' => 'text', 'text' => (string) ($details['order_reference'] ?? '')]]];
        $body[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1',
            'parameters' => [['type' => 'payload', 'payload' => 'cancel_order:'.$cancelToken]]];

        try {
            $response = Http::connectTimeout(5)->timeout(15)
                ->withHeaders(['authkey' => $authKey])->asJson()
                ->post((string) config('whatsappcenter.nexmsg.template_send_url'), [
                    'accountId' => $accountId,
                    'templateName' => $templateName,
                    'to' => $to,
                    'languageCode' => 'en',
                    'components' => $body,
                ]);
            if ($response->failed() || data_get($response->json(), 'success') === false) {
                Log::warning('NexMsg companion order action template was rejected.', [
                    'account_id_suffix' => substr($accountId, -6),
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $exception) {
            // The payment action was already accepted. Do not retry it and
            // create a duplicate merely because the companion action failed.
            Log::warning('NexMsg companion order action template failed.', [
                'account_id_suffix' => substr($accountId, -6),
                'exception' => $exception::class,
            ]);
        }
    }

    public function normalizeInbound(Request $request): NormalizedWebhookMessage
    {
        $payload = $request->json()->all();
        $message = (array) (data_get($payload, 'entry.0.changes.0.value.messages.0')
            ?: data_get($payload, 'message') ?: []);
        $order = (array) (data_get($message, 'order') ?: data_get($payload, 'order') ?: []);
        $type = mb_substr((string) (data_get($message, 'type') ?: data_get($payload, 'type', 'text')), 0, 30);
        $items = $type === 'order' ? $this->normalizeOrderItems(
            data_get($order, 'product_items', data_get($order, 'items', []))
        ) : [];
        $text = data_get($message, 'text.body')
            ?: data_get($message, 'button.text')
            ?: data_get($message, 'interactive.button_reply.id')
            ?: data_get($message, 'interactive.button_reply.title')
            ?: data_get($message, 'interactive.list_reply.id')
            ?: data_get($message, 'interactive.list_reply.title')
            ?: data_get($payload, 'text');

        return new NormalizedWebhookMessage(
            providerEventId: trim((string) (data_get($message, 'id') ?: data_get($payload, 'event_id') ?: $request->header('X-Request-Id'))),
            // Meta-compatible callbacks expose the WABA ID at entry[0].id.
            // NexMsg flat callbacks may expose it directly as wabaId/waba_id.
            providerPhoneId: trim((string) (data_get($payload, 'wabaId') ?: data_get($payload, 'waba_id')
                ?: data_get($payload, 'entry.0.id') ?: data_get($payload, 'account.wabaId'))),
            sender: $this->normalizePhone((string) (data_get($message, 'from') ?: data_get($payload, 'from'))),
            type: $type,
            text: $this->boundedText($text),
            safePayload: ['timestamp' => data_get($message, 'timestamp') ?: data_get($payload, 'timestamp')],
            providerOrderId: $type === 'order' ? $this->boundedIdentifier(
                data_get($order, 'id') ?: data_get($order, 'order_id') ?: data_get($payload, 'order_id')
            ) : null,
            catalogId: $type === 'order' ? $this->boundedIdentifier(data_get($order, 'catalog_id')) : null,
            orderItems: $items,
            location: $type === 'location' ? [
                'latitude' => data_get($message, 'location.latitude') ?: data_get($payload, 'location.latitude'),
                'longitude' => data_get($message, 'location.longitude') ?: data_get($payload, 'location.longitude'),
                'name' => $this->boundedText(data_get($message, 'location.name') ?: data_get($payload, 'location.name')),
                'address' => $this->boundedText(data_get($message, 'location.address') ?: data_get($payload, 'location.address')),
            ] : null,
        );
    }

    public function sendCatalog(
        WhatsAppProviderProfile $profile,
        WhatsAppPhoneNumber $phoneNumber,
        string $recipient,
        string $bodyText,
        string $footerText,
        ?string $operationId = null,
    ): array {
        $credentials = $profile->credentials ?? [];
        $authKey = trim((string) ($credentials['auth_key'] ?? ''));
        $wabaId = preg_replace('/\D+/', '', (string) $phoneNumber->provider_phone_id);
        $to = $this->normalizePhone($recipient);

        if ($authKey === '' || ! preg_match('/^\d{6,32}$/', $wabaId) || ! preg_match('/^\d{10,15}$/', $to)) {
            throw new RuntimeException('NexMsg catalog configuration is invalid.');
        }

        $request = Http::connectTimeout(5)->timeout(15)
            ->withHeaders(['authkey' => $authKey, 'Accept' => 'application/json'])
            ->when($operationId, fn ($request) => $request->withHeader('Idempotency-Key', mb_substr($operationId, 0, 128)))
            ->asJson();
        $response = $request
            ->retry(2, 300, function (\Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            }, throw: false)
            ->post((string) config('whatsappcenter.nexmsg.catalog_send_url'), [
                'wabaId' => $wabaId,
                'to' => $to,
                'bodyText' => mb_substr(trim($bodyText), 0, 1024),
                'footerText' => mb_substr(trim($footerText), 0, 256),
            ]);

        $body = $response->json();
        $accepted = data_get($body, 'success') === true
            || data_get($body, 'accepted') === true
            || filled(data_get($body, 'messageId'))
            || filled(data_get($body, 'message_id'))
            || filled(data_get($body, 'data.messageId'));
        if ($response->failed() || ! $accepted) {
            // Deliberately omit provider response content: it may contain phone
            // numbers, account metadata, or other customer information.
            throw new RuntimeException("NexMsg rejected the catalog request with status {$response->status()}.");
        }

        return [
            'status' => $response->status(),
            'provider_message_id' => data_get($body, 'messageId')
                ?: data_get($body, 'message_id')
                ?: data_get($body, 'data.messageId'),
        ];
    }

    public function sendText(WhatsAppProviderProfile $profile, WhatsAppPhoneNumber $phoneNumber, string $recipient, string $body): array
    {
        $credentials = $profile->credentials ?? [];
        $authKey = trim((string) ($credentials['auth_key'] ?? ''));
        $accountId = trim((string) ($credentials['account_id'] ?? ''));
        $to = $this->normalizePhone($recipient);
        abort_unless($authKey !== '' && preg_match('/^[a-f0-9]{24}$/i', $accountId)
            && preg_match('/^\d{10,15}$/', $to), 422, 'NexMsg text configuration is invalid. Use the 24-character NexMsg Account ID, not the WABA ID.');

        $response = Http::connectTimeout(5)->timeout(15)
            ->withHeaders(['authkey' => $authKey, 'Accept' => 'application/json'])
            ->asJson()
            ->retry(2, 300, function (\Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->serverError());
            }, throw: false)
            ->post((string) config('whatsappcenter.nexmsg.text_send_url'), [
                'accountId' => $accountId,
                'to' => $to,
                'text' => mb_substr(trim($body), 0, 4096),
            ]);
        $payload = $response->json();
        if ($response->failed() || data_get($payload, 'success') !== true) {
            $reason = $this->safeProviderFailure($payload);
            throw new RuntimeException("NexMsg rejected the text message with status {$response->status()}".($reason ? ": {$reason}" : '.'));
        }

        return [
            'status' => $response->status(),
            'provider_message_id' => data_get($payload, 'messageId') ?: data_get($payload, 'message_id'),
        ];
    }

    private function safeProviderFailure(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['error.message', 'errors.0.message', 'data.message', 'error', 'message'] as $path) {
            $value = data_get($payload, $path);
            if (! is_scalar($value) || blank((string) $value)) {
                continue;
            }
            $message = preg_replace(
                '/(api[_ -]?key|auth[_ -]?key|token|secret|authorization)(["\'\s:=]+)[^,\s}]+/i',
                '$1$2[REDACTED]',
                str((string) $value)->squish()->limit(300)->toString(),
            );

            return $message ?: null;
        }

        return null;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        }

        return $digits;
    }

    private function normalizeOrderItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)->take(100)->map(function ($item): array {
            return [
                'product_retailer_id' => $this->boundedIdentifier(data_get($item, 'product_retailer_id')),
                'quantity' => filter_var(data_get($item, 'quantity'), FILTER_VALIDATE_INT) ?: 0,
            ];
        })->all();
    }

    private function boundedIdentifier(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 191);
    }
}
