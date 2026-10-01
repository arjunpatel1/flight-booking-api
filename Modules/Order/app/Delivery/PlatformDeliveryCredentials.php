<?php
namespace Modules\Order\Delivery;
use Illuminate\Contracts\Encryption\DecryptException;
use Modules\Setting\Models\Setting;
class PlatformDeliveryCredentials
{
    public function apiKey(): string { return $this->value('delivery_uengage_api_key', true); }
    public function storeId(): string { return $this->value('delivery_uengage_store_id', false); }
    public function operationalValue(string $key, mixed $default = null): mixed
    {
        if (! in_array($key, ['automatic_partner_assignment_enabled', 'delivery_quotes_enabled', 'delivery_selection_strategy', 'delivery_provider_codes', 'maximum_provider_delivery_cost', 'maximum_delivery_eta_minutes', 'auto_fallback_partner_enabled'], true)) {
            throw new ProviderUnavailable('PROVIDER_SETTING_NOT_ALLOWED');
        }
        $row = $this->globalRow($key);
        return $row ? $row->payload : $default;
    }
    private function globalRow(string $key): ?Setting
    {
        return Setting::query()->withoutGlobalScopes()->where('setting_scope', 'global')
            ->whereNull('tenant_id')->whereNull('branch_id')->where('key', $key)->first();
    }
    private function value(string $key, bool $secret): string
    {
        $row = $this->globalRow($key);
        if (! $row) return '';
        if ($secret) {
            if (! $row->is_encryptable) return '';
            try { $value = decrypt($row->getRawOriginal('payload')); }
            catch (DecryptException) { return ''; }
        } else {
            $value = $row->payload;
        }
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }
}
