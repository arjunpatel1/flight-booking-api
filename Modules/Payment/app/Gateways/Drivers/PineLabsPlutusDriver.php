<?php

namespace Modules\Payment\Gateways\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Gateways\Contracts\PaymentGatewayDriver;
use Modules\Payment\Gateways\Data\GatewayChargeRequest;
use Modules\Payment\Gateways\Data\GatewayChargeResult;
use Modules\Payment\Gateways\GatewayPaymentStatus;

/**
 * Pine Labs Plutus Smart — Cloud Based Integration.
 *
 * Flow (all HTTP, the physical terminal performs the EMV/card-present steps):
 *   1. POST /UploadBilledTransaction  → routes the bill to the in-store terminal
 *   2. poll POST /GetCloudBasedTransactionStatus until the cardholder completes
 *   3. (optional) reverse via UploadBilledTransaction with a void TransactionType
 *
 * Credentials come from config('payment.gateways.pinelabs'); the per-call
 * `terminalId` selects the StoreID/terminal when a branch has several.
 */
class PineLabsPlutusDriver implements PaymentGatewayDriver
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function key(): string
    {
        return 'pinelabs';
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['base_url'])
            && !empty($this->config['merchant_id'])
            && !empty($this->config['security_token']);
    }

    public function charge(GatewayChargeRequest $request): GatewayChargeResult
    {
        $reference = $this->numericReference($request->reference);

        $upload = $this->post('UploadBilledTransaction', [
            'TransactionNumber' => $reference,
            'SequenceNumber' => 1,
            'AllowedPaymentMode' => 0, // 0 = all modes; terminal decides
            'AmountInPaisa' => $request->amountInMinorUnits(),
            'UserID' => $this->config['user_id'] ?? '',
            'PlutusTransactionReferenceID' => $reference,
        ], $request->terminalId);

        $uploadCode = (int) ($upload['ResponseCode'] ?? -1);
        if ($uploadCode !== 0) {
            return GatewayChargeResult::failed(
                $upload['ResponseMessage'] ?? 'Unable to start terminal transaction',
                $upload,
            );
        }

        return $this->pollUntilComplete((string) $reference, $request->terminalId);
    }

    public function status(string $gatewayTransactionId, array $context = []): GatewayChargeResult
    {
        $response = $this->post('GetCloudBasedTransactionStatus', [
            'PlutusTransactionReferenceID' => $gatewayTransactionId,
        ], $context['terminal_id'] ?? null);

        return $this->mapStatus($gatewayTransactionId, $response);
    }

    public function reverse(string $gatewayTransactionId, GatewayChargeRequest $original): GatewayChargeResult
    {
        if (($this->config['allow_reversal'] ?? true) !== true) {
            return GatewayChargeResult::failed('Reversal disabled for this gateway');
        }

        $response = $this->post('UploadBilledTransaction', [
            'TransactionType' => 'reversal',
            'AmountInPaisa' => $original->amountInMinorUnits(),
            'PlutusTransactionReferenceID' => $this->numericReference($original->reference . '9'),
            'OriginalPlutusTransactionReferenceID' => $gatewayTransactionId,
        ], $original->terminalId);

        $code = (int) ($response['ResponseCode'] ?? -1);

        return new GatewayChargeResult(
            status: $code === 0 ? GatewayPaymentStatus::Approved : GatewayPaymentStatus::Failed,
            gatewayTransactionId: $gatewayTransactionId,
            message: $response['ResponseMessage'] ?? null,
            raw: $response,
        );
    }

    private function pollUntilComplete(string $reference, ?string $terminalId): GatewayChargeResult
    {
        $deadline = microtime(true) + (float) ($this->config['poll_timeout_seconds'] ?? 60);
        $interval = max(1, (int) ($this->config['poll_interval_seconds'] ?? 3));

        do {
            $result = $this->status($reference, ['terminal_id' => $terminalId]);
            if (!$result->isPending()) {
                return $result;
            }
            if (microtime(true) + $interval >= $deadline) {
                break;
            }
            sleep($interval);
        } while (microtime(true) < $deadline);

        // Timed out still pending — caller must not record a payment; the txn
        // can be reconciled later via status().
        return GatewayChargeResult::pending($reference, $result->raw ?? []);
    }

    /** @param array<string,mixed> $response */
    private function mapStatus(string $reference, array $response): GatewayChargeResult
    {
        $code = (int) ($response['ResponseCode'] ?? -1);
        $message = $response['ResponseMessage'] ?? null;

        if ($code === 0) {
            $card = $this->extractTransactionData($response['TransactionData'] ?? []);
            return new GatewayChargeResult(
                status: GatewayPaymentStatus::Approved,
                gatewayTransactionId: $reference,
                approvalCode: $card['approval_code'] ?? null,
                cardLast4: $card['card_last4'] ?? null,
                cardScheme: $card['card_scheme'] ?? null,
                message: $message,
                raw: $response,
            );
        }

        $pending = in_array($code, $this->config['pending_codes'] ?? [1001, 1002], true)
            || ($message && preg_match('/progress|initiat|pending|wait/i', $message));

        return new GatewayChargeResult(
            status: $pending ? GatewayPaymentStatus::Pending : GatewayPaymentStatus::Declined,
            gatewayTransactionId: $reference,
            message: $message,
            raw: $response,
        );
    }

    /**
     * Pine Labs returns card details as a list of {Tag/name, value} entries;
     * pull out the bits we keep for reconciliation. Best-effort across versions.
     *
     * @param array<int,array<string,mixed>> $data
     * @return array<string,string>
     */
    private function extractTransactionData(array $data): array
    {
        $out = [];
        foreach ($data as $entry) {
            $name = strtolower((string) ($entry['Tag'] ?? $entry['name'] ?? ''));
            $value = (string) ($entry['value'] ?? $entry['Value'] ?? '');
            if ($value === '') {
                continue;
            }
            if (str_contains($name, 'approval')) {
                $out['approval_code'] = $value;
            } elseif (str_contains($name, 'card number') || $name === 'cardnumber') {
                $out['card_last4'] = substr(preg_replace('/\D/', '', $value) ?: $value, -4);
            } elseif (str_contains($name, 'acquirer') || str_contains($name, 'card type') || str_contains($name, 'scheme')) {
                $out['card_scheme'] = $value;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $payload */
    private function post(string $endpoint, array $payload, ?string $terminalId): array
    {
        $body = array_merge([
            'MerchantID' => $this->config['merchant_id'] ?? '',
            'StoreID' => $terminalId ?: ($this->config['store_id'] ?? ''),
            'ClientID' => $this->config['client_id'] ?? '',
            'SecurityToken' => $this->config['security_token'] ?? '',
            'AutoCancelDurationInMinutes' => $this->config['auto_cancel_minutes'] ?? 5,
        ], $payload);

        try {
            $response = Http::baseUrl(rtrim((string) $this->config['base_url'], '/'))
                ->timeout((int) ($this->config['http_timeout_seconds'] ?? 30))
                ->acceptJson()
                ->asJson()
                ->post('/' . ltrim($endpoint, '/'), $body);

            return $response->json() ?? ['ResponseCode' => -1, 'ResponseMessage' => 'Empty response'];
        } catch (\Throwable $e) {
            Log::warning('Pine Labs request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return ['ResponseCode' => -1, 'ResponseMessage' => $e->getMessage()];
        }
    }

    /** Pine Labs needs a numeric PlutusTransactionReferenceID. */
    private function numericReference(string $reference): int
    {
        $digits = preg_replace('/\D/', '', $reference);
        if ($digits !== null && $digits !== '' && strlen($digits) <= 18) {
            return (int) $digits;
        }
        // Derive a stable-ish numeric from a hash when the ref isn't numeric.
        return (int) (substr((string) crc32($reference), 0, 9) . substr((string) microtime(true) * 1000, -4));
    }
}
