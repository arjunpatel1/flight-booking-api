<?php

namespace Modules\Payment\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Services\WhatsApp\WhatsAppTemplateCatalog;
use Modules\Payment\Models\TenantPaymentGatewayConfig;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class TenantPaymentGatewayController extends Controller
{
    private const PROVIDERS = ['manual', 'razorpay', 'pinelabs', 'direct_upi'];

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $configured = TenantPaymentGatewayConfig::query()
            ->where('tenant_id', $tenantId)
            ->with('branches:id,name')
            ->get()->keyBy('provider');

        return ApiResponse::success([
            'providers' => collect(self::PROVIDERS)->map(fn (string $provider) => $this->present($provider, $configured->get($provider)))->values(),
            'branches' => DB::table('branches')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'checkout_options' => [
                'cash_on_delivery' => (bool) setting('customer_payment_cod_enabled', false),
                'pay_at_counter' => (bool) setting('customer_payment_counter_enabled', true),
                'razorpay' => (bool) setting('customer_payment_razorpay_enabled', false),
                'whatsapp_bill' => (bool) setting('customer_payment_whatsapp_bill_enabled', false),
                'whatsapp_greeting' => (string) setting('customer_payment_whatsapp_greeting', 'Thank you for ordering with us.'),
            ],
        ]);
    }

    public function updateCheckoutOptions(Request $request): JsonResponse
    {
        $this->tenantId($request);
        $data = $request->validate([
            'cash_on_delivery' => ['required', 'boolean'],
            'pay_at_counter' => ['required', 'boolean'],
            'razorpay' => ['required', 'boolean'],
            'whatsapp_bill' => ['required', 'boolean'],
            'whatsapp_greeting' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['razorpay']) {
            $config = TenantPaymentGatewayConfig::query()->where('tenant_id', $this->tenantId($request))
                ->where('provider', 'razorpay')->where('enabled', true)->first();
            abort_unless($config && filled(data_get($config->credentials, 'linked_account_id'))
                && (bool) config('payment.gateways.razorpay.partner_auth_enabled')
                && filled(config('payment.gateways.razorpay.key_id'))
                && filled(config('payment.gateways.razorpay.key_secret')),
                422, 'Assign this restaurant a Razorpay Partner client account before offering online payment.');
        }
        if ($data['whatsapp_bill']) {
            $billingTemplate = collect(WhatsAppTemplateCatalog::merge(setting('whatsapp_templates') ?: []))->first(function ($template) {
                $template = is_string($template) ? ['name' => $template] : $template;

                return ($template['event'] ?? null) === 'billing_sent' && ($template['is_active'] ?? true);
            });
            abort_unless((bool) setting('whatsapp_enabled', false) && $billingTemplate, 422, 'Enable WhatsApp and configure an active billing_sent utility template first.');
        }

        setting([
            'customer_payment_cod_enabled' => $data['cash_on_delivery'],
            'delivery_cod_enabled' => $data['cash_on_delivery'],
            'customer_payment_counter_enabled' => $data['pay_at_counter'],
            'customer_payment_razorpay_enabled' => $data['razorpay'],
            'customer_payment_whatsapp_bill_enabled' => $data['whatsapp_bill'],
            'customer_payment_whatsapp_greeting' => trim((string) ($data['whatsapp_greeting'] ?? '')),
        ]);

        return ApiResponse::success(['checkout_options' => $data], message: 'Customer payment options updated.');
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), Response::HTTP_NOT_FOUND);
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'test_mode' => ['required', 'boolean'],
            'preferred' => ['required', 'boolean'],
            'settlement_identity' => ['nullable', 'string', 'max:190'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer'],
            'credentials' => ['sometimes', 'array', 'max:20'],
            'credentials.*' => ['nullable', 'string', 'max:4096'],
        ]);
        $branchIds = collect($data['branch_ids'] ?? [])->unique()->values();
        $allowedCredentialKeys = match ($provider) {
            'razorpay' => ['linked_account_id'],
            'pinelabs' => ['merchant_id', 'client_id', 'security_token'],
            'direct_upi' => ['base_url', 'api_key', 'merchant_upi_account_id', 'webhook_secret'],
            default => [],
        };
        abort_if(collect(array_keys($data['credentials'] ?? []))->diff($allowedCredentialKeys)->isNotEmpty(), Response::HTTP_UNPROCESSABLE_ENTITY);
        if ($provider === 'razorpay' && filled(data_get($data, 'credentials.linked_account_id'))) {
            abort_unless((bool) preg_match('/^acc_[A-Za-z0-9]{14,}$/', (string) data_get($data, 'credentials.linked_account_id')),
                Response::HTTP_UNPROCESSABLE_ENTITY, 'Enter a valid Razorpay Partner client account ID.');
        }
        if ($provider === 'direct_upi' && filled(data_get($data, 'credentials.merchant_upi_account_id'))) {
            abort_unless(Str::isUuid((string) data_get($data, 'credentials.merchant_upi_account_id')), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (filled(data_get($data, 'credentials.base_url'))) {
            $parts = parse_url((string) data_get($data, 'credentials.base_url'));
            abort_unless(($parts['scheme'] ?? null) === 'https' && filled($parts['host'] ?? null), Response::HTTP_UNPROCESSABLE_ENTITY);
            $host = strtolower((string) $parts['host']);
            abort_if($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local'), Response::HTTP_UNPROCESSABLE_ENTITY);
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                abort_unless(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        abort_if($branchIds->isNotEmpty() && DB::table('branches')
            ->whereIn('id', $branchIds)->where('tenant_id', '!=', $tenantId)->exists(), Response::HTTP_UNPROCESSABLE_ENTITY);
        abort_if($branchIds->isNotEmpty() && DB::table('branches')
            ->whereIn('id', $branchIds)->where('tenant_id', $tenantId)->count() !== $branchIds->count(), Response::HTTP_UNPROCESSABLE_ENTITY);

        $config = DB::transaction(function () use ($request, $provider, $tenantId, $data, $branchIds) {
            if ($data['preferred']) {
                TenantPaymentGatewayConfig::query()->where('tenant_id', $tenantId)->update(['preferred' => false]);
            }
            $config = TenantPaymentGatewayConfig::query()->firstOrNew([
                'tenant_id' => $tenantId,
                'provider' => $provider,
            ]);
            $updates = collect($data)->except(['branch_ids', 'credentials'])->all();
            if (array_key_exists('credentials', $data) && collect($data['credentials'])->filter(fn ($v) => filled($v))->isNotEmpty()) {
                $updates['credentials'] = array_replace(
                    $config->credentials ?? [],
                    collect($data['credentials'])->filter(fn ($v) => filled($v))->all(),
                );
                $updates['credential_version'] = $config->exists
                    ? ((int) $config->credential_version + 1)
                    : 1;
            }
            $updates['updated_by'] = $request->user()->id;
            $config->fill($updates)->save();
            $config->branches()->sync($branchIds);

            return $config->refresh()->load('branches:id,name');
        });

        return ApiResponse::success($this->present($provider, $config));
    }

    public function test(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), Response::HTTP_NOT_FOUND);
        $config = TenantPaymentGatewayConfig::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('provider', $provider)->firstOrFail();
        $required = match ($provider) {
            'razorpay' => ['linked_account_id'],
            'pinelabs' => ['merchant_id', 'client_id', 'security_token'],
            'direct_upi' => ['base_url', 'api_key', 'merchant_upi_account_id', 'webhook_secret'],
            default => [],
        };
        $healthy = collect($required)->every(fn ($key) => filled(($config->credentials ?? [])[$key] ?? null));
        if ($provider === 'razorpay') {
            $healthy = $healthy
                && (bool) preg_match('/^acc_[A-Za-z0-9]{14,}$/', (string) data_get($config->credentials, 'linked_account_id'))
                && (bool) config('payment.gateways.razorpay.partner_auth_enabled')
                && filled(config('payment.gateways.razorpay.key_id'))
                && filled(config('payment.gateways.razorpay.key_secret'));
        }
        $config->update(['last_tested_at' => now(), 'last_test_status' => $healthy ? 'configured' : 'incomplete']);

        return ApiResponse::success(['healthy' => $healthy, 'status' => $config->last_test_status]);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->user()?->tenantId();
        abort_if(! $tenantId, Response::HTTP_FORBIDDEN);

        return (int) $tenantId;
    }

    private function present(string $provider, ?TenantPaymentGatewayConfig $config): array
    {
        return [
            'provider' => $provider,
            'configured' => $config !== null && ! empty($config->credentials),
            'enabled' => (bool) ($config?->enabled ?? false),
            'test_mode' => (bool) ($config?->test_mode ?? true),
            'preferred' => (bool) ($config?->preferred ?? false),
            'settlement_identity' => $config?->settlement_identity,
            'branch_ids' => $config?->branches->pluck('id')->values()->all() ?? [],
            'credentials' => $config?->maskedCredentials() ?? [],
            'credential_version' => $config?->credential_version,
            'last_tested_at' => $config?->last_tested_at?->toIso8601String(),
            'last_test_status' => $config?->last_test_status,
            'webhook_url' => match ($provider) {
                'direct_upi' => $config ? url('/api/v1/payment-gateways/direct-upi/webhook/'.$config->webhook_key) : null,
                'razorpay' => url('/api/v1/payment-gateways/razorpay/partner/webhook'),
                default => null,
            },
        ];
    }
}
