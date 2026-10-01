<?php

namespace Modules\Order\Delivery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Tenant logistics wallet. This is separate from customer payment wallets. */
final class DeliveryWallet
{
    public function account(int $tenantId, ?string $currency = null): object
    {
        $account = DB::table('delivery_wallet_accounts')->where('tenant_id', $tenantId)->first();
        if ($account) return $account;

        $currency ??= (string) (DB::table('branches')->where('tenant_id', $tenantId)->value('currency') ?: 'INR');
        DB::table('delivery_wallet_accounts')->insertOrIgnore([
            'tenant_id' => $tenantId, 'currency' => strtoupper($currency),
            'available_balance' => 0, 'reserved_balance' => 0,
            'low_balance_threshold' => 0, 'block_booking_when_insufficient' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('delivery_wallet_accounts')->where('tenant_id', $tenantId)->firstOrFail();
    }

    public function reserve(int $tenantId, int $deliveryId, int $orderId, int|float|string $amount, string $key): object
    {
        return $this->post($tenantId, 'reserve', $amount, $key, 'Provider delivery cost reserved before booking transmission.', $deliveryId, $orderId);
    }

    public function capture(int $tenantId, int $deliveryId, int $orderId, int|float|string $amount, string $key): object
    {
        return $this->post($tenantId, 'capture', $amount, $key, 'Confirmed provider delivery cost captured.', $deliveryId, $orderId);
    }

    public function release(int $tenantId, int $deliveryId, int $orderId, int|float|string $amount, string $key, string $reason): object
    {
        return $this->post($tenantId, 'release', $amount, $key, $reason, $deliveryId, $orderId);
    }

    /** Credit a confirmed provider refund against an earlier captured delivery cost. */
    public function refundCapture(int $tenantId, int $deliveryId, int $orderId, int|float|string $amount,
        string $key, string $reason, string $reference, ?int $actorId): object
    {
        return $this->post($tenantId, 'refund', $amount, $key, $reason, $deliveryId, $orderId, $actorId, $reference);
    }

    /** Amount reserved for one delivery that has not been captured or released. */
    public function outstanding(int $tenantId, int $deliveryId, int $orderId): string
    {
        $ledger = DB::table('delivery_wallet_transactions')
            ->where('tenant_id', $tenantId)
            ->where('order_delivery_id', $deliveryId)
            ->where('order_id', $orderId)
            ->selectRaw("SUM(CASE WHEN type = 'reserve' THEN amount ELSE 0 END) reserved")
            ->selectRaw("SUM(CASE WHEN type IN ('capture','release') THEN amount ELSE 0 END) settled")
            ->first();

        return DeliveryMoney::decimal(bcsub(
            DeliveryMoney::decimal($ledger->reserved ?? 0),
            DeliveryMoney::decimal($ledger->settled ?? 0),
            4,
        ));
    }

    public function releaseOutstanding(int $tenantId, int $deliveryId, int $orderId, string $key, string $reason): ?object
    {
        $amount = $this->outstanding($tenantId, $deliveryId, $orderId);

        return bccomp($amount, '0', 4) > 0
            ? $this->release($tenantId, $deliveryId, $orderId, $amount, $key, $reason)
            : null;
    }

    public function adjust(int $tenantId, string $type, int|float|string $amount, string $key, string $reason,
        ?int $actorId, ?array $metadata = null): object
    {
        if (! in_array($type, ['credit', 'debit'], true)) {
            throw ValidationException::withMessages(['type' => 'Adjustment must be credit or debit.']);
        }
        return $this->post($tenantId, $type, $amount, $key, $reason, null, null, $actorId, null, $metadata);
    }

    private function post(int $tenantId, string $type, int|float|string $amount, string $key, string $reason,
        ?int $deliveryId = null, ?int $orderId = null, ?int $actorId = null, ?string $reference = null,
        ?array $metadata = null): object
    {
        $decimal = DeliveryMoney::decimal($amount);
        if (bccomp($decimal, '0', 4) <= 0 || strlen($key) > 191 || trim($key) === '') {
            throw ValidationException::withMessages(['amount' => 'A positive amount and valid idempotency key are required.']);
        }

        return DB::transaction(function () use ($tenantId, $type, $decimal, $key, $reason, $deliveryId, $orderId, $actorId, $reference, $metadata): object {
            $existing = DB::table('delivery_wallet_transactions')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                $this->assertSameOperation($existing, $type, $decimal, $deliveryId, $orderId, $reference);
                $existing->_idempotent_replay = true;
                return $existing;
            }

            $this->account($tenantId);
            $account = DB::table('delivery_wallet_accounts')->where('tenant_id', $tenantId)->lockForUpdate()->firstOrFail();
            // Recheck after the account lock: a concurrent request may have
            // posted this idempotency key while this transaction was waiting.
            $existing = DB::table('delivery_wallet_transactions')->where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                $this->assertSameOperation($existing, $type, $decimal, $deliveryId, $orderId, $reference);
                $existing->_idempotent_replay = true;
                return $existing;
            }
            $availableBefore = (string) $account->available_balance;
            $reservedBefore = (string) $account->reserved_balance;
            if (in_array($type, ['reserve', 'capture', 'release', 'refund'], true)) {
                if (! $deliveryId || ! $orderId) {
                    throw ValidationException::withMessages(['wallet' => 'A delivery and order are required for delivery wallet operations.']);
                }
                $ledger = DB::table('delivery_wallet_transactions')
                    ->where('tenant_id', $tenantId)
                    ->where('order_delivery_id', $deliveryId)
                    ->where('order_id', $orderId)
                    ->selectRaw("SUM(CASE WHEN type = 'reserve' THEN amount ELSE 0 END) reserved")
                    ->selectRaw("SUM(CASE WHEN type IN ('capture','release') THEN amount ELSE 0 END) settled")
                    ->first();
                $deliveryReserved = DeliveryMoney::decimal($ledger->reserved ?? 0);
                $deliverySettled = DeliveryMoney::decimal($ledger->settled ?? 0);
                $outstanding = bcsub($deliveryReserved, $deliverySettled, 4);
                if ($type === 'reserve' && bccomp($deliveryReserved, '0', 4) > 0) {
                    throw ValidationException::withMessages(['wallet' => 'This delivery already has a wallet reservation.']);
                }
                if (in_array($type, ['capture', 'release'], true) && bccomp($outstanding, $decimal, 4) < 0) {
                    throw ValidationException::withMessages(['wallet' => 'This delivery does not have enough reserved balance for the settlement.']);
                }
                if ($type === 'refund') {
                    $referenceUsed = DB::table('delivery_wallet_transactions')
                        ->where('tenant_id', $tenantId)->where('type', 'refund')
                        ->where('reference', $reference)->exists();
                    if ($referenceUsed) {
                        throw ValidationException::withMessages(['provider_reference' => 'This provider refund reference was already posted.']);
                    }
                    $captured = DeliveryMoney::decimal(DB::table('delivery_wallet_transactions')
                        ->where('tenant_id', $tenantId)->where('order_delivery_id', $deliveryId)
                        ->where('order_id', $orderId)->where('type', 'capture')->sum('amount'));
                    $refunded = DeliveryMoney::decimal(DB::table('delivery_wallet_transactions')
                        ->where('tenant_id', $tenantId)->where('order_delivery_id', $deliveryId)
                        ->where('order_id', $orderId)->where('type', 'refund')->sum('amount'));
                    if (bccomp(bcsub($captured, $refunded, 4), $decimal, 4) < 0 || ! filled($reference)) {
                        throw ValidationException::withMessages(['wallet' => filled($reference)
                            ? 'Refund exceeds the captured provider delivery cost.'
                            : 'A provider refund reference is required.']);
                    }
                }
            }
            [$availableAfter, $reservedAfter] = match ($type) {
                'credit' => [bcadd($availableBefore, $decimal, 4), $reservedBefore],
                'debit' => [bcsub($availableBefore, $decimal, 4), $reservedBefore],
                'reserve' => [bcsub($availableBefore, $decimal, 4), bcadd($reservedBefore, $decimal, 4)],
                'capture' => [$availableBefore, bcsub($reservedBefore, $decimal, 4)],
                'release' => [bcadd($availableBefore, $decimal, 4), bcsub($reservedBefore, $decimal, 4)],
                'refund' => [bcadd($availableBefore, $decimal, 4), $reservedBefore],
            };
            if (bccomp($availableAfter, '0', 4) < 0 || bccomp($reservedAfter, '0', 4) < 0) {
                throw ValidationException::withMessages(['wallet' => 'The delivery wallet has insufficient available or reserved balance.']);
            }

            DB::table('delivery_wallet_accounts')->where('id', $account->id)->update([
                'available_balance' => $availableAfter, 'reserved_balance' => $reservedAfter, 'updated_at' => now(),
            ]);
            $uuid = (string) Str::uuid();
            DB::table('delivery_wallet_transactions')->insert([
                'uuid' => $uuid, 'wallet_account_id' => $account->id, 'tenant_id' => $tenantId,
                'order_delivery_id' => $deliveryId, 'order_id' => $orderId, 'actor_id' => $actorId,
                'type' => $type, 'status' => 'posted', 'amount' => $decimal,
                'available_before' => $availableBefore, 'available_after' => $availableAfter,
                'reserved_before' => $reservedBefore, 'reserved_after' => $reservedAfter,
                'idempotency_key' => $key, 'reference' => $reference, 'reason' => mb_substr($reason, 0, 500),
                'metadata' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $transaction = DB::table('delivery_wallet_transactions')->where('uuid', $uuid)->firstOrFail();
            $transaction->_idempotent_replay = false;
            return $transaction;
        }, 3);
    }

    private function assertSameOperation(object $existing, string $type, string $amount,
        ?int $deliveryId, ?int $orderId, ?string $reference): void
    {
        if ($existing->type !== $type || bccomp((string) $existing->amount, $amount, 4) !== 0
            || (int) ($existing->order_delivery_id ?? 0) !== (int) ($deliveryId ?? 0)
            || (int) ($existing->order_id ?? 0) !== (int) ($orderId ?? 0)
            || (string) ($existing->reference ?? '') !== (string) ($reference ?? '')) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This key was already used for a different wallet operation.',
            ]);
        }
    }
}
