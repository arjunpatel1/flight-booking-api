<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Controllers\Controller;
use Modules\Saas\Models\Tenant;
use Modules\Saas\Support\TenantContext;
use Modules\Setting\Models\Setting;
use Modules\Setting\Services\Setting\SettingServiceInterface;
use Modules\Support\ApiResponse;

class TenantWhatsAppSettingsController extends Controller
{
    private function scoped(int $tenantId, callable $callback): mixed
    {
        Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId);
        $context = app(TenantContext::class);
        $settings = app(SettingServiceInterface::class);
        $previous = $context->id();
        $context->setId($tenantId);
        $settings->refreshSettingBinding();
        try {
            return $callback();
        } finally {
            $context->setId($previous);
            $settings->refreshSettingBinding();
        }
    }

    public function show(int $tenantId)
    {
        return $this->scoped($tenantId, fn () => ApiResponse::success([
            'provider' => setting('whatsapp_provider', 'msg91'),
            'enabled' => filter_var(setting('whatsapp_enabled', false), FILTER_VALIDATE_BOOL),
            'account_id' => setting('whatsapp_nexmsg_account_id'),
            'auth_key_configured' => filled(setting('whatsapp_nexmsg_auth_key')),
            'test_recipient' => setting('whatsapp_nexmsg_test_recipient'),
        ]));
    }

    public function update(Request $request, int $tenantId)
    {
        $data = $request->validate([
            'account_id' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9_-]+$/'],
            'auth_key' => ['nullable', 'string', 'max:4096'],
            'enabled' => ['required', 'boolean'],
            'test_recipient' => ['nullable', 'string', 'regex:/^[1-9][0-9]{7,14}$/'],
        ]);
        return $this->scoped($tenantId, function () use ($data, $request, $tenantId) {
            DB::transaction(function () use ($data, $request, $tenantId) {
                $tenant = Tenant::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($tenantId);
                $oldAccount = (string) setting('whatsapp_nexmsg_account_id');
                if ($data['enabled'] && blank($data['auth_key'] ?? null) && ($oldAccount !== $data['account_id'] || !filled(setting('whatsapp_nexmsg_auth_key')))) {
                    throw ValidationException::withMessages(['auth_key' => 'An Auth Key is required for a new or changed NexMsg account.']);
                }
                $encrypted = ['whatsapp_nexmsg_account_id' => $data['account_id']];
                // Never retain another account’s secret when the account changes.
                if ($oldAccount !== $data['account_id']) $encrypted['whatsapp_nexmsg_auth_key'] = null;
                if (filled($data['auth_key'] ?? null)) $encrypted['whatsapp_nexmsg_auth_key'] = $data['auth_key'];
                Setting::setMany([
                    'encryptable' => $encrypted,
                    'whatsapp_provider' => 'nexmsg',
                    'whatsapp_enabled' => $data['enabled'],
                    'whatsapp_nexmsg_test_recipient' => $data['test_recipient'] ?? null,
                ]);
                activity('saas_whatsapp_configuration')->event('updated')->causedBy($request->user())->performedOn($tenant)
                    ->withProperties(['tenant_id' => $tenantId, 'provider' => 'nexmsg', 'enabled' => $data['enabled'], 'credentials_rotated' => filled($data['auth_key'] ?? null)])
                    ->log('Tenant WhatsApp configuration updated');
            });
            return ApiResponse::success(null, 'NexMsg configuration saved. No message was sent.');
        });
    }
}
