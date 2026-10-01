<?php

namespace Modules\User\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Http\Controllers\Controller;
use Modules\Support\ApiResponse;
use Modules\User\Enums\DefaultRole;
use Modules\User\Models\User;

class CustomerWalletAdminController extends Controller
{
    public function credit(Request $request, int $customer): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'description' => ['required', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        abort_if($idempotencyKey === '' || mb_strlen($idempotencyKey) > 100, 422, 'A valid Idempotency-Key header is required.');

        $actor = $request->user();
        $tenantId = (int) $actor->tenant_id;
        $owner = User::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->findOrFail($customer);
        abort_unless($owner->roles()->where('name', DefaultRole::Customer->value)->exists(), 422, 'The selected user is not a customer.');
        $currency = strtoupper($data['currency'] ?? setting('default_currency') ?? config('app.currency', 'INR'));

        $transaction = DB::transaction(function () use ($actor, $data, $idempotencyKey, $owner, $tenantId, $currency) {
            $existing = DB::table('customer_wallet_transactions')->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless((int) $existing->customer_id === (int) $owner->id && $existing->type === 'staff_credit', 409, 'This idempotency key was already used for a different wallet operation.');
                return $existing;
            }

            DB::table('customer_wallet_accounts')->insertOrIgnore([
                'tenant_id' => $tenantId, 'customer_id' => $owner->id, 'currency' => $currency,
                'balance' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $account = DB::table('customer_wallet_accounts')->where('tenant_id', $tenantId)
                ->where('customer_id', $owner->id)->where('currency', $currency)->lockForUpdate()->firstOrFail();
            $amount = round((float) $data['amount'], 4);
            $balanceAfter = (float) $account->balance + $amount;
            DB::table('customer_wallet_accounts')->where('id', $account->id)->update(['balance' => $balanceAfter, 'updated_at' => now()]);
            $reference = (string) Str::uuid();
            DB::table('customer_wallet_transactions')->insert([
                'reference' => $reference, 'tenant_id' => $tenantId, 'customer_id' => $owner->id,
                'type' => 'staff_credit', 'direction' => 'credit', 'amount' => $amount,
                'balance_after' => $balanceAfter, 'currency' => $currency, 'idempotency_key' => $idempotencyKey,
                'description' => trim($data['description']), 'meta' => json_encode(['credited_by' => $actor->id]),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('customer_wallet_transactions')->where('reference', $reference)->first();
        });

        return ApiResponse::success(['transaction' => $transaction], message: 'Customer wallet credited.');
    }
}
