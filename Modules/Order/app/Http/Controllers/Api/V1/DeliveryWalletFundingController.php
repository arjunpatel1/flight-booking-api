<?php

namespace Modules\Order\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\Controller;
use Modules\Order\Delivery\DeliveryCommercialTerms;
use Modules\Order\Delivery\DeliveryWallet;
use Modules\Saas\Support\TenantContext;
use Modules\Support\ApiResponse;

class DeliveryWalletFundingController extends Controller
{
    public function tenantIndex(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $this->tenantId($request, $context);
        return ApiResponse::success([
            'methods' => $this->methods(),
            'gateway' => $this->gatewayStatus(),
            'requests' => $this->presentRequests($this->requests()->where('topups.tenant_id', $tenantId)->limit(25)->get()),
        ]);
    }

    public function requestManual(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $this->tenantId($request, $context);
        $data = $request->validate([
            'method_uuid' => ['required', 'uuid'],
            'payable_amount' => ['required', 'numeric', 'min:500', 'max:999999999'],
            'payment_reference' => ['required', 'string', 'min:4', 'max:191'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);
        $method = DB::table('delivery_wallet_funding_methods')->where('uuid', $data['method_uuid'])
            ->where('enabled', true)->whereIn('type', ['upi', 'qr', 'bank'])->firstOrFail();
        $duplicate = DB::table('delivery_wallet_top_up_requests')->where('payment_reference', $data['payment_reference'])
            ->whereIn('status', ['pending', 'approved'])->exists();
        if ($duplicate) throw ValidationException::withMessages(['payment_reference' => 'This payment reference is already under review or approved.']);
        $amounts = $this->amountsFromPayable((float) $data['payable_amount']);
        $proof = $request->file('proof')?->store('delivery-wallet/proofs/'.now()->format('Y/m'), 'public');
        $uuid = (string) Str::uuid();
        DB::table('delivery_wallet_top_up_requests')->insert([
            'uuid' => $uuid, 'tenant_id' => $tenantId, 'funding_method_id' => $method->id,
            'requested_by' => $request->user()?->id, 'channel' => 'manual', 'status' => 'pending',
            'currency' => 'INR', ...$amounts, 'payment_reference' => trim($data['payment_reference']),
            'proof_path' => $proof, 'created_at' => now(), 'updated_at' => now(),
        ]);
        activity('delivery_wallet')->event('delivery_wallet_top_up_requested')->causedBy($request->user())
            ->withProperties(['tenant_id' => $tenantId, 'request_uuid' => $uuid, ...$amounts])->log('Delivery wallet top-up requested.');
        return ApiResponse::success(['uuid' => $uuid, ...$amounts], 'Payment submitted for platform approval.');
    }

    public function startGateway(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $this->tenantId($request, $context);
        $data = $request->validate(['payable_amount' => ['required', 'numeric', 'min:500', 'max:999999999']]);
        $config = config('payment.gateways.razorpay', []);
        abort_unless(filled($config['key_id'] ?? null) && filled($config['key_secret'] ?? null), 503, 'Online wallet payment is not configured.');
        $amounts = $this->amountsFromPayable((float) $data['payable_amount']);
        $uuid = (string) Str::uuid();
        $response = Http::baseUrl((string) ($config['base_url'] ?? 'https://api.razorpay.com/v1'))
            ->withBasicAuth((string) $config['key_id'], (string) $config['key_secret'])->acceptJson()->asJson()
            ->timeout((int) ($config['http_timeout_seconds'] ?? 20))->post('/orders', [
                'amount' => (int) round($amounts['payable_amount'] * 100), 'currency' => 'INR',
                'receipt' => 'DW-'.substr(str_replace('-', '', $uuid), 0, 30), 'payment_capture' => 1,
                'notes' => ['purpose' => 'delivery_wallet_top_up', 'tenant_id' => (string) $tenantId, 'request_uuid' => $uuid],
            ]);
        if (! $response->successful() || ! filled($response->json('id'))) {
            throw ValidationException::withMessages(['gateway' => 'The payment gateway could not start this wallet payment.']);
        }
        DB::table('delivery_wallet_top_up_requests')->insert([
            'uuid' => $uuid, 'tenant_id' => $tenantId, 'requested_by' => $request->user()?->id,
            'channel' => 'razorpay', 'status' => 'awaiting_payment', 'currency' => 'INR', ...$amounts,
            'gateway_order_id' => $response->json('id'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ApiResponse::success([
            'uuid' => $uuid, 'key_id' => $config['key_id'], 'razorpay_order_id' => $response->json('id'),
            'amount' => (int) round($amounts['payable_amount'] * 100), 'currency' => 'INR', ...$amounts,
        ]);
    }

    public function verifyGateway(Request $request, TenantContext $context, string $uuid, DeliveryWallet $wallet): JsonResponse
    {
        $tenantId = $this->tenantId($request, $context);
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:191'],
            'razorpay_payment_id' => ['required', 'string', 'max:191'],
            'razorpay_signature' => ['required', 'string', 'max:500'],
        ]);
        $config = config('payment.gateways.razorpay', []);
        $topup = DB::table('delivery_wallet_top_up_requests')->where('uuid', $uuid)->where('tenant_id', $tenantId)->firstOrFail();
        abort_unless($topup->channel === 'razorpay' && in_array($topup->status, ['awaiting_payment', 'approved'], true), 409, 'This payment request cannot be verified.');
        abort_unless(hash_equals((string) $topup->gateway_order_id, $data['razorpay_order_id']), 422, 'Payment order mismatch.');
        $signature = hash_hmac('sha256', $data['razorpay_order_id'].'|'.$data['razorpay_payment_id'], (string) ($config['key_secret'] ?? ''));
        abort_unless(hash_equals($signature, strtolower($data['razorpay_signature'])), 422, 'Payment signature verification failed.');
        $response = Http::baseUrl((string) ($config['base_url'] ?? 'https://api.razorpay.com/v1'))
            ->withBasicAuth((string) $config['key_id'], (string) $config['key_secret'])->acceptJson()
            ->timeout((int) ($config['http_timeout_seconds'] ?? 20))->get('/payments/'.$data['razorpay_payment_id']);
        $payment = $response->json();
        abort_unless($response->successful() && in_array($payment['status'] ?? null, ['captured'], true), 422, 'The gateway has not confirmed this payment.');
        abort_unless(($payment['order_id'] ?? null) === $topup->gateway_order_id
            && (int) ($payment['amount'] ?? 0) === (int) round((float) $topup->payable_amount * 100)
            && strtoupper((string) ($payment['currency'] ?? '')) === 'INR', 422, 'Verified payment details do not match this wallet request.');
        $this->approve($topup, $request->user()?->id, $wallet, $data['razorpay_payment_id'], 'Verified Razorpay wallet payment.');
        return ApiResponse::success(null, 'Payment verified and wallet credited.');
    }

    public function cancelGateway(Request $request, TenantContext $context, string $uuid): JsonResponse
    {
        $tenantId = $this->tenantId($request, $context);
        $updated = DB::table('delivery_wallet_top_up_requests')
            ->where('uuid', $uuid)->where('tenant_id', $tenantId)
            ->where('channel', 'razorpay')->where('status', 'awaiting_payment')
            ->update(['status' => 'cancelled', 'review_note' => 'Customer closed the payment checkout.', 'updated_at' => now()]);
        abort_unless($updated === 1, 409, 'This payment request can no longer be cancelled.');
        return ApiResponse::success(null, 'Online payment request cancelled.');
    }

    public function processGatewayWebhook(array $entity, DeliveryWallet $wallet): void
    {
        if (($entity['status'] ?? null) !== 'captured' || data_get($entity, 'notes.purpose') !== 'delivery_wallet_top_up') return;
        $topup = DB::table('delivery_wallet_top_up_requests')->where('gateway_order_id', $entity['order_id'] ?? '')->first();
        if (! $topup || $topup->status === 'approved') return;
        abort_unless((string) data_get($entity, 'notes.request_uuid') === $topup->uuid
            && (int) data_get($entity, 'notes.tenant_id') === (int) $topup->tenant_id
            && (int) ($entity['amount'] ?? 0) === (int) round((float) $topup->payable_amount * 100)
            && strtoupper((string) ($entity['currency'] ?? '')) === 'INR', 422, 'Wallet webhook payment mismatch.');
        $this->approve($topup, null, $wallet, (string) ($entity['id'] ?? ''), 'Verified Razorpay webhook payment.');
    }

    public function platformMethods(): JsonResponse { return ApiResponse::success($this->methods(false)); }

    public function storeMethod(Request $request): JsonResponse { return $this->saveMethod($request); }
    public function updateMethod(Request $request, string $uuid): JsonResponse { return $this->saveMethod($request, $uuid); }

    private function saveMethod(Request $request, ?string $uuid = null): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['upi', 'qr', 'bank'])], 'label' => ['required', 'string', 'max:120'],
            'enabled' => ['required', 'boolean'], 'display_order' => ['nullable', 'integer', 'between:0,999'],
            'upi_id' => ['nullable', 'string', 'max:191'], 'account_name' => ['nullable', 'string', 'max:191'],
            'account_number' => ['nullable', 'string', 'max:191'], 'bank_name' => ['nullable', 'string', 'max:191'],
            'ifsc' => ['nullable', 'string', 'max:30'], 'instructions' => ['nullable', 'string', 'max:1000'],
            'qr_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);
        if ($data['type'] === 'upi' && ! preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z0-9.-]{2,64}$/', trim((string) ($data['upi_id'] ?? '')))) {
            throw ValidationException::withMessages(['upi_id' => 'Enter a valid UPI ID, for example name@bank.']);
        }
        if ($data['type'] === 'bank') {
            $bankErrors = [];
            if (blank($data['account_name'] ?? null)) $bankErrors['account_name'] = 'Account holder name is required.';
            if (! preg_match('/^\d{6,34}$/', preg_replace('/\s+/', '', (string) ($data['account_number'] ?? '')))) $bankErrors['account_number'] = 'Enter a valid 6 to 34 digit account number.';
            if (blank($data['bank_name'] ?? null)) $bankErrors['bank_name'] = 'Bank name is required.';
            if (! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper(trim((string) ($data['ifsc'] ?? ''))))) $bankErrors['ifsc'] = 'Enter a valid IFSC, for example HDFC0001234.';
            if ($bankErrors !== []) throw ValidationException::withMessages($bankErrors);
            $data['account_number'] = preg_replace('/\s+/', '', (string) $data['account_number']);
            $data['ifsc'] = strtoupper(trim((string) $data['ifsc']));
        }
        $existing = $uuid ? DB::table('delivery_wallet_funding_methods')->where('uuid', $uuid)->firstOrFail() : null;
        $path = $data['type'] === 'qr'
            ? ($request->file('qr_image')?->store('delivery-wallet/methods', 'public') ?: $existing?->qr_image_path)
            : null;
        if ($data['type'] === 'qr' && blank($path)) throw ValidationException::withMessages(['qr_image' => 'Upload a payment QR image.']);
        $details = collect($data)->only(['upi_id','account_name','account_number','bank_name','ifsc','instructions'])->filter(fn ($v) => filled($v))->all();
        $values = ['type' => $data['type'], 'label' => $data['label'], 'enabled' => $data['enabled'],
            'display_order' => $data['display_order'] ?? 0, 'details' => json_encode($details), 'qr_image_path' => $path, 'updated_at' => now()];
        if ($existing) DB::table('delivery_wallet_funding_methods')->where('id', $existing->id)->update($values);
        else { $uuid = (string) Str::uuid(); DB::table('delivery_wallet_funding_methods')->insert(['uuid' => $uuid, ...$values, 'created_at' => now()]); }
        return ApiResponse::success(['uuid' => $uuid], 'Funding method saved.');
    }

    public function platformRequests(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'pending', 'awaiting_payment', 'approved', 'rejected', 'cancelled', 'failed'])],
            'channel' => ['nullable', Rule::in(['all', 'manual', 'razorpay'])],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ]);
        $status = $data['status'] ?? 'pending';
        $channel = $data['channel'] ?? 'all';
        $query = $this->requests()
            ->when($status !== 'all', fn ($q) => $q->where('topups.status', $status))
            ->when($channel !== 'all', fn ($q) => $q->where('topups.channel', $channel))
            ->when(isset($data['tenant_id']), fn ($q) => $q->where('topups.tenant_id', $data['tenant_id']));
        return ApiResponse::success($this->presentRequests($query->limit(500)->get()));
    }

    public function review(Request $request, string $uuid, DeliveryWallet $wallet): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $topup = DB::table('delivery_wallet_top_up_requests')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
        abort_unless($topup->channel === 'manual' && $topup->status === 'pending', 409, 'This request was already reviewed or cannot be manually reviewed.');
        if ($data['action'] === 'approve') $this->approve($topup, $request->user()?->id, $wallet, $topup->payment_reference, $data['note'] ?: 'Manual payment approved by platform administrator.');
        else {
            $updated = DB::table('delivery_wallet_top_up_requests')->where('id', $topup->id)
                ->where('channel', 'manual')->where('status', 'pending')->update([
                    'status' => 'rejected', 'reviewed_by' => $request->user()?->id,
                    'review_note' => $data['note'], 'reviewed_at' => now(), 'updated_at' => now(),
                ]);
            abort_unless($updated === 1, 409, 'This request was already reviewed.');
        }
        return ApiResponse::success(null, 'Top-up request '.$data['action'].'d.');
    }

    private function approve(object $topup, ?int $actorId, DeliveryWallet $wallet, ?string $reference, string $note): void
    {
        DB::transaction(function () use ($topup, $actorId, $wallet, $reference, $note): void {
            $locked = DB::table('delivery_wallet_top_up_requests')->where('id', $topup->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'approved') return;
            abort_unless(in_array($locked->status, ['pending', 'awaiting_payment'], true), 409, 'This request cannot be approved.');
            $metadata = ['wallet_top_up' => ['credit_amount' => (float) $locked->credit_amount, 'gst_rate' => (float) $locked->gst_rate,
                'gst_amount' => (float) $locked->gst_amount, 'payable_amount' => (float) $locked->payable_amount,
                'request_uuid' => $locked->uuid, 'channel' => $locked->channel]];
            $wallet->adjust((int) $locked->tenant_id, 'credit', $locked->credit_amount, 'wallet-topup:'.$locked->uuid,
                'Approved delivery wallet top-up.', $actorId, $metadata);
            DB::table('delivery_wallet_top_up_requests')->where('id', $locked->id)->update([
                'status' => 'approved', 'gateway_payment_id' => $locked->channel === 'razorpay' ? $reference : null,
                'reviewed_by' => $actorId, 'review_note' => $note, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    private function methods(bool $enabledOnly = true): array
    {
        return DB::table('delivery_wallet_funding_methods')->when($enabledOnly, fn ($q) => $q->where('enabled', true))
            ->orderBy('display_order')->orderBy('id')->get()->map(function ($row): array {
                $details = filled($row->details) ? json_decode($row->details, true) : [];
                return ['uuid' => $row->uuid, 'type' => $row->type, 'label' => $row->label, 'enabled' => (bool) $row->enabled,
                    'display_order' => $row->display_order, 'details' => $details,
                    'qr_image_url' => $row->qr_image_path ? Storage::disk('public')->url($row->qr_image_path) : null];
            })->all();
    }

    private function requests()
    {
        return DB::table('delivery_wallet_top_up_requests as topups')->leftJoin('tenants', 'tenants.id', '=', 'topups.tenant_id')
            ->leftJoin('delivery_wallet_funding_methods as methods', 'methods.id', '=', 'topups.funding_method_id')
            ->select(['topups.uuid','topups.tenant_id','tenants.name as tenant_name','topups.channel','topups.status','topups.currency',
                'topups.credit_amount','topups.gst_rate','topups.gst_amount','topups.payable_amount','topups.payment_reference',
                'topups.proof_path','topups.gateway_order_id','topups.gateway_payment_id','methods.label as method_label','topups.review_note','topups.created_at','topups.reviewed_at'])
            ->orderByDesc('topups.id');
    }

    private function presentRequests($rows): array
    {
        return $rows->map(function ($row): array {
            $item = (array) $row;
            $item['proof_url'] = $row->proof_path ? Storage::disk('public')->url($row->proof_path) : null;
            $item['request_id'] = 'DW-'.strtoupper(substr(str_replace('-', '', (string) $row->uuid), 0, 12));
            $item['order_id'] = $row->gateway_order_id ?: $item['request_id'];
            unset($item['proof_path']);
            return $item;
        })->all();
    }

    private function amountsFromPayable(float $payable): array
    {
        $amounts = app(DeliveryCommercialTerms::class)->walletTopUpFromPayable($payable);
        return ['credit_amount' => $amounts['wallet_credit'], 'gst_rate' => $amounts['gst_rate'],
            'gst_amount' => $amounts['gst_amount'], 'payable_amount' => $amounts['gross_amount']];
    }

    private function gatewayStatus(): array
    {
        $config = config('payment.gateways.razorpay', []);
        return ['razorpay' => filled($config['key_id'] ?? null) && filled($config['key_secret'] ?? null)];
    }

    private function tenantId(Request $request, TenantContext $context): int
    {
        $actor = $request->user(); abort_unless($actor, 401);
        $actorTenantId = (int) ($actor->tenantId() ?: 0); $contextTenantId = (int) ($context->id() ?: 0);
        if ($actor->assignedToTenant()) { abort_unless($actorTenantId > 0, 403); abort_if($contextTenantId > 0 && $contextTenantId !== $actorTenantId, 404); return $actorTenantId; }
        abort_unless($contextTenantId > 0, 403, 'A restaurant context is required.'); return $contextTenantId;
    }
}
