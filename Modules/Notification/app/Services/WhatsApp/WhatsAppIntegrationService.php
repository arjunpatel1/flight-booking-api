<?php

namespace Modules\Notification\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Modules\Notification\Services\WhatsApp\Providers\Msg91Provider;
use Modules\Saas\Support\TenantContext;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use RuntimeException;

class WhatsAppIntegrationService
{
    public function status(): array
    {
        $nexMsgTemplates = collect(setting('whatsapp_templates') ?: [])
            ->filter(fn ($template) => is_array($template)
                && ($template['provider'] ?? null) === 'nexmsg'
                && ($template['approval_status'] ?? null) === 'approved'
                && ($template['is_active'] ?? true));
        $requiredEvents = ['customer_otp', 'order_submitted', 'order_accepted', 'preparing', 'ready', 'completed', 'cancelled', 'billing_sent'];
        $managedAssignment = $this->managedNexMsgAssignment();
        $capabilities = (array) ($managedAssignment?->capabilities ?? []);
        $orderingEnabled = data_get($capabilities, 'ordering') === true || in_array('ordering', $capabilities, true);
        if ($orderingEnabled) {
            $requiredEvents[] = 'whatsapp_order_received';
            $requiredEvents[] = 'whatsapp_order_received_payment';
        }
        // Delivery tracking is required only when the paid provider workflow is
        // enabled for this restaurant. Normal in-house delivery remains usable
        // without a provider integration or provider-specific templates.
        if (setting('third_party_delivery_enabled', false)) {
            $requiredEvents[] = 'delivery_update';
        }
        $mappedEvents = $nexMsgTemplates->pluck('event')->filter()->unique();
        $missingEvents = collect($requiredEvents)->reject(fn (string $event) => $mappedEvents->contains($event))->values()->all();
        $capabilityGaps = [];
        $submitted = $nexMsgTemplates->firstWhere('event', 'order_submitted');
        if (is_array($submitted)) {
            // The secure order page owns payment, the five-minute cancellation
            // gate and live tracking. One stable button avoids exposing raw
            // payment/cancellation URLs in the message body.
            if (! $this->hasUrlButton($submitted, ['tracking_link'])) {
                $capabilityGaps[] = 'order_submitted.tracking_link';
            }
        }
        if (setting('third_party_delivery_enabled', false)) {
            $deliveryUpdate = $nexMsgTemplates->firstWhere('event', 'delivery_update');
            if (is_array($deliveryUpdate) && ! $this->hasUrlButton($deliveryUpdate, ['tracking_link'])) {
                $capabilityGaps[] = 'delivery_update.tracking_link';
            }
        }

        $managed = $this->managedNexMsgCredentials();
        // An active SaaS assignment is the source of truth after a managed
        // number moves between restaurants/accounts. Tenant settings may
        // still contain the previous self-managed account and must not route
        // syncs or sends back to it.
        $nexMsgAccountId = (string) (($managed['account_id'] ?? null) ?: setting('whatsapp_nexmsg_account_id'));
        $nexMsgAuthKey = (string) (($managed['auth_key'] ?? null) ?: setting('whatsapp_nexmsg_auth_key'));

        return [
            'provider' => $managed !== [] ? 'nexmsg' : (setting('whatsapp_provider') ?: 'msg91'),
            'nexmsg' => [
                'configured' => filled($nexMsgAccountId) && filled($nexMsgAuthKey),
                'account_id' => filled($nexMsgAccountId)
                    ? '••••'.substr($nexMsgAccountId, -6)
                    : null,
                'last_template_sync' => setting('whatsapp_nexmsg_last_template_sync'),
                'approved_template_count' => $nexMsgTemplates->count(),
                'mapped_event_count' => $mappedEvents->count(),
                'missing_required_events' => $missingEvents,
                'template_capability_gaps' => $capabilityGaps,
                'ready' => filled($nexMsgAccountId)
                    && filled($nexMsgAuthKey)
                    && $missingEvents === []
                    && $capabilityGaps === [],
            ],
            'msg91' => [
                'utility' => $this->profileStatus('utility'),
                'marketing' => $this->profileStatus('marketing'),
                'marketing_reuses_utility' => (bool) setting('whatsapp_msg91_marketing_reuse_utility', true),
            ],
            'meta' => [
                'configured' => filled(setting('whatsapp_meta_access_token')) && filled(setting('whatsapp_meta_phone_number_id')),
                'last_validation' => setting('whatsapp_meta_last_validation'),
                'last_template_sync' => setting('whatsapp_meta_last_template_sync'),
            ],
            'webhook' => [
                'last_received_at' => setting('whatsapp_webhook_last_received_at'),
                'last_provider' => setting('whatsapp_webhook_last_provider'),
                'healthy' => ($last = setting('whatsapp_webhook_last_received_at')) ? now()->diffInHours($last) <= 24 : false,
            ],
        ];
    }

    /** Provider-approved URL components are required for actual WhatsApp buttons. */
    private function hasUrlButton(array $template, array $variables): bool
    {
        return collect($template['buttons'] ?? [])->contains(function ($button) use ($variables): bool {
            return is_array($button)
                && strtolower((string) ($button['type'] ?? '')) === 'url'
                && in_array((string) ($button['variable'] ?? ''), $variables, true);
        });
    }

    public function validate(string $provider, ?string $profile = null): array
    {
        $result = $provider === 'meta' ? $this->validateMeta() : $this->validateMsg91($profile ?: 'utility');
        setting(["whatsapp_{$provider}".($profile ? "_{$profile}" : '').'_last_validation' => ['at' => now()->toIso8601String(), ...$result]]);

        return $result;
    }

    /**
     * Persist a tenant's approved provider catalogue. NexMsg does not expose a
     * portable template-list endpoint, so its portal export is accepted here
     * and normalised instead of maintaining account-specific template maps.
     */
    public function syncTemplates(string $provider, ?string $profile = null, array $catalog = [], bool $statusOnly = false): array
    {
        if ($provider === 'nexmsg' && $catalog === []) {
            $catalog = $this->fetchNexMsgCatalog();
        }

        $templates = match ($provider) {
            'meta' => $this->metaTemplates(),
            'nexmsg' => $this->nexMsgTemplates($catalog),
            default => $this->msg91Templates($profile ?: 'utility'),
        };

        $existing = collect(setting('whatsapp_templates') ?: [])
            ->map(fn ($item) => is_string($item) ? ['id' => $item, 'template_id' => $item, 'name' => $item] : $item)
            ->filter(fn ($item) => is_array($item))
            ->keyBy(fn (array $item) => $item['template_id'] ?? $item['id'] ?? $item['name'] ?? '');

        // A managed number may move to another provider account. A full sync
        // is authoritative for that account, so stale provider records must not
        // keep the tenant falsely green or route sends to the previous account.
        if (! $statusOnly) {
            $incomingIds = collect($templates)->pluck('template_id')->filter()->values();
            $existing = $existing->reject(fn (array $item) => strtolower((string) ($item['provider'] ?? '')) === strtolower($provider)
                && ! $incomingIds->contains($item['template_id'] ?? null)
            );
        }

        foreach ($templates as $template) {
            $providerTemplateId = $template['template_id'];
            $current = $existing->get($providerTemplateId);
            if ($statusOnly && is_array($current)) {
                $status = collect($template)->only([
                    'provider', 'provider_approval_status', 'provider_template_id',
                    'provider_rejection_reason', 'provider_synced_at',
                ])->all();
                $existing->put($providerTemplateId, [...$current, ...$status]);
            } else {
                $existing->put($providerTemplateId, [...(is_array($current) ? $current : []), ...$template]);
            }
        }
        setting([
            'whatsapp_templates' => $existing->values()->all(),
            "whatsapp_{$provider}".($profile ? "_{$profile}" : '').'_last_template_sync' => [
                'at' => now()->toIso8601String(), 'count' => count($templates), 'status' => 'success',
            ],
        ]);

        return ['provider' => $provider, 'profile' => $profile, 'count' => count($templates), 'templates' => $templates];
    }

    private function nexMsgTemplates(array $catalog): array
    {
        if ($catalog === []) {
            throw new RuntimeException('Paste the approved NexMsg template catalogue exported for this tenant.');
        }

        $managed = $this->managedNexMsgCredentials();
        $accountId = (string) (($managed['account_id'] ?? null) ?: setting('whatsapp_nexmsg_account_id'));
        $configuredNames = collect(setting('whatsapp_templates') ?: [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => (string) ($item['template_id'] ?? $item['id'] ?? ''))
            ->filter()->values();

        $templates = collect($catalog)
            ->filter(function ($row) use ($accountId) {
                if (! is_array($row)) {
                    return false;
                }
                $enabled = filter_var($row['enabled'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
                $rowAccountId = (string) ($row['accountId'] ?? $row['account_id'] ?? '');

                return $enabled && ($accountId === '' || $rowAccountId === '' || hash_equals($accountId, $rowAccountId));
            })
            ->map(function (array $row) use ($configuredNames): ?array {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    return null;
                }

                $catalogName = match ($name) {
                    'address_delivery_update_v2', 'address_delivery_update_v3' => 'address_delivery_update',
                    'address_delivery_update_v4', 'address_delivery_update_v5' => 'address_delivery_update',
                    'nexdine_staff_order_details_v2', 'nexdine_staff_order_details_v3' => 'staff_order_received_professional',
                    'nexdine_order_received_actions_v3', 'nexdine_order_received_actions_v4',
                    'nexdine_order_received_actions_v7' => 'whatsapp_order_received',
                    'nexdine_order_submitted_track_v2' => 'order_submitted',
                    'nexdine_order_confirmed_track_v2' => 'order_accepted',
                    'nexdine_order_preparing_track_v2' => 'order_preparing',
                    'nexdine_order_ready_track_v2' => 'order_ready',
                    'nexdine_order_completed_v2' => 'order_completed',
                    'nexdine_order_cancelled_v2' => 'order_cancelled',
                    'nexdine_billing_sent_v2' => 'billing_sent',
                    'nexdine_payment_received_v2' => 'payment_received',
                    'nexdine_payment_failed_v2' => 'payment_failed',
                    'nexdine_refund_processed_v2' => 'refund_processed',
                    'nexdine_feedback_request_v3' => 'feedback_request',
                    default => $name,
                };
                $default = collect(WhatsAppTemplateCatalog::defaults())->first(
                    fn (array $template) => in_array($catalogName, [
                        $template['id'] ?? null,
                        $template['template_id'] ?? null,
                    ], true),
                );

                // Provider template names are tenant-owned and frequently do
                // not match NexDine's stock IDs. Preserve an explicit event
                // mapping from the catalogue import and recognise the two
                // approved legacy aliases already used by NexMsg accounts.
                $event = trim((string) ($row['event'] ?? '')) ?: match ($name) {
                    'order_confirmation' => 'order_accepted',
                    'order_delivered_successfully' => 'completed',
                    default => $default['event'] ?? null,
                };

                // NexMsg accounts may contain templates for several products
                // or businesses. Persist only templates that belong to the
                // NexDine catalogue or carry an explicit NexDine event map.
                // This keeps tenant UI data isolated and prevents the settings
                // payload from overflowing when a provider account is shared.
                if (! is_array($default) && blank($event) && ! $configuredNames->contains($name)) {
                    return null;
                }

                preg_match_all('/\{\{\s*([0-9]+)\s*\}\}/', (string) ($row['body'] ?? ''), $bodyMatches);
                $bodyCount = count(array_unique($bodyMatches[1] ?? []));
                $defaultBodyVariables = is_array($default)
                    ? collect($default['component_keys'] ?? [])
                        ->zip($default['variables'] ?? [])
                        ->filter(fn ($pair) => str_starts_with((string) ($pair[0] ?? ''), 'body_'))
                        ->pluck(1)->values()->all()
                    : [];
                $explicitVariables = array_values(array_filter((array) ($row['variables'] ?? []), 'is_string'));
                $explicitVariablesArePlaceholders = $explicitVariables !== []
                    && collect($explicitVariables)->every(
                        fn (string $variable) => preg_match('/^body_[0-9]+$/', $variable) === 1,
                    );
                $aliasVariables = match ($name) {
                    'order_confirmation' => ['customer_name', 'restaurant_name', 'order_id', 'order_total_numeric', 'order_date', 'item_quantity'],
                    'order_delivered_successfully' => ['customer_name', 'restaurant_name', 'order_id', 'order_total_numeric', 'delivered_at'],
                    default => [],
                };
                $variables = count($explicitVariables) === $bodyCount && ! $explicitVariablesArePlaceholders
                    ? $explicitVariables
                    : (count($aliasVariables) === $bodyCount
                        ? $aliasVariables
                        : (count($defaultBodyVariables) === $bodyCount
                            ? $defaultBodyVariables
                            : ($bodyCount > 0 ? array_map(fn (int $index) => "body_{$index}", range(1, $bodyCount)) : [])));
                $componentKeys = $bodyCount > 0
                    ? array_map(fn (int $index) => "body_{$index}", range(1, $bodyCount))
                    : [];
                $buttons = [];

                foreach (array_values($row['buttons'] ?? []) as $index => $button) {
                    if (! is_array($button)) {
                        continue;
                    }
                    $type = strtolower((string) ($button['type'] ?? ''));
                    $url = (string) ($button['url'] ?? '');
                    if ($type === 'url' && preg_match('/\{\{\s*[0-9]+\s*\}\}/', $url)) {
                        $key = 'button_url_'.($index + 1);
                        $context = strtolower(implode(' ', [
                            $button['text'] ?? '',
                            $url,
                            $default['event'] ?? '',
                            $default['id'] ?? $name,
                        ]));
                        $variable = match (true) {
                            str_contains($context, 'cancel') => 'cancel_token',
                            str_contains($context, 'feedback'), str_contains($context, 'rate') => 'feedback_link',
                            str_contains($context, 'invoice'), str_contains($context, 'receipt'), str_contains($context, 'bill') => 'payment_link',
                            str_contains($context, 'reorder'), str_contains($context, 'order again') => 'order_again_link',
                            str_contains($context, 'track') => 'tracking_link',
                            str_contains($context, 'open order'), str_contains($context, 'staff/order-open') => 'order_link',
                            str_contains($context, 'otp'), str_contains($context, 'verification') => 'button_code',
                            default => $key,
                        };
                        $variables[] = $variable;
                        $componentKeys[] = $key;
                        $buttons[] = ['type' => 'url', 'index' => $index, 'label' => $button['text'] ?? '', 'url' => $url, 'variable' => $variable];
                    } elseif ($type === 'quick_reply') {
                        $buttons[] = ['type' => 'quick_reply', 'index' => $index, 'label' => $button['text'] ?? ''];
                    }
                }

                $approvalStatus = strtolower((string) ($row['status'] ?? $row['approval_status'] ?? 'unknown'));

                return [
                    'id' => $default['id'] ?? $name,
                    'template_id' => $name,
                    'name' => $default['name'] ?? $name,
                    'description' => $default['description'] ?? null,
                    'category' => strtolower((string) ($row['category'] ?? 'utility')),
                    'event' => $event,
                    'message' => $row['body'] ?? null,
                    'is_active' => $approvalStatus === 'approved',
                    'language_code' => $row['language'] ?? $row['languageCode'] ?? $row['language_code'] ?? 'en',
                    'variables' => $variables,
                    'component_keys' => $componentKeys,
                    'buttons' => $buttons,
                    'provider' => 'nexmsg',
                    'approval_status' => $approvalStatus,
                    'provider_approval_status' => $approvalStatus,
                    'provider_template_id' => $row['id'] ?? $row['templateId'] ?? $row['template_id'] ?? null,
                    'provider_rejection_reason' => $row['rejection_reason'] ?? $row['rejected_reason'] ?? $row['reason'] ?? null,
                    'provider_synced_at' => now()->toIso8601String(),
                ];
            })
            ->filter()
            ->values();

        // Meta does not allow an approved template body to be edited. Once a
        // corrected title-case replacement is approved, make it the sole
        // active provider template for that event. Pending replacements never
        // interrupt an already-approved notification path.
        $preferredByEvent = [
            'staff_order_received' => 'nexdine_staff_order_details_v3',
            'staff_delivery_not_created' => 'nexdine_delivery_not_created_v2',
            'staff_delivery_rider_unassigned' => 'nexdine_rider_assignment_delayed_v1',
            'staff_delivery_cancelled' => 'nexdine_delivery_cancelled_admin_v1',
            'whatsapp_order_received' => 'nexdine_order_received_actions_v7',
            'order_submitted' => 'nexdine_order_submitted_track_v2',
            'completed' => 'nexdine_order_completed_v2',
            'cancelled' => 'nexdine_order_cancelled_v2',
            'billing_sent' => 'nexdine_billing_sent_v2',
            'payment_received' => 'nexdine_payment_received_v2',
            'payment_failed' => 'nexdine_payment_failed_v2',
            'refund_processed' => 'nexdine_refund_processed_v2',
            'feedback_request' => 'nexdine_feedback_request_v3',
            'delivery_update' => 'address_delivery_update_v5',
        ];
        $approvedPreferred = $templates
            ->filter(fn (array $template) => ($template['approval_status'] ?? null) === 'approved'
                && ($preferredByEvent[$template['event'] ?? ''] ?? null) === ($template['template_id'] ?? null))
            ->pluck('template_id', 'event');

        return $templates
            ->map(function (array $template) use ($approvedPreferred): array {
                $preferred = $approvedPreferred->get($template['event'] ?? '');
                if (filled($preferred)) {
                    $template['is_active'] = ($template['template_id'] ?? null) === $preferred;
                }

                return $template;
            })
            ->values()
            ->all();
    }

    private function fetchNexMsgCatalog(): array
    {
        $sendUrl = (string) config('notification.providers.nexmsg.api_url');
        $managed = $this->managedNexMsgCredentials();
        $accountId = (string) (($managed['account_id'] ?? null) ?: setting('whatsapp_nexmsg_account_id'));
        $authKey = (string) (($managed['auth_key'] ?? null) ?: setting('whatsapp_nexmsg_auth_key'));
        if ($sendUrl === '' || $accountId === '' || $authKey === '') {
            throw new RuntimeException('NexMsg Account ID and Auth Key are required for template sync.');
        }

        $catalogUrl = preg_replace('~/send/template/?$~', '/templates', $sendUrl);
        if (! is_string($catalogUrl) || $catalogUrl === $sendUrl) {
            throw new RuntimeException('NexMsg template catalogue endpoint is not configured.');
        }
        $response = Http::acceptJson()->withHeaders(['authkey' => $authKey])->timeout(15)
            ->get($catalogUrl, ['accountId' => $accountId, 'refresh' => 1]);
        if ($response->failed()) {
            throw new RuntimeException("NexMsg template sync failed with status {$response->status()}.");
        }

        return is_array($response->json()) ? $response->json() : [];
    }

    private function validateMsg91(string $profile): array
    {
        $credentials = app(Msg91Provider::class)->credentials($profile);
        $response = Http::withHeaders(['authkey' => $credentials['auth_key'], 'accept' => 'application/json'])
            ->get('https://control.msg91.com/api/v5/whatsapp/whatsapp-activation/');
        if ($response->failed()) {
            throw new RuntimeException("MSG91 {$profile} validation failed with status {$response->status()}.");
        }
        $numbers = collect(data_get($response->json(), 'data', $response->json() ?? []))->flatten()->map(fn ($value) => (string) $value);

        return ['configured' => true, 'connected' => $numbers->contains(fn ($value) => str_contains($value, (string) $credentials['integrated_number'])), 'status' => $response->status()];
    }

    private function msg91Templates(string $profile): array
    {
        $credentials = app(Msg91Provider::class)->credentials($profile);
        $response = Http::withHeaders(['authkey' => $credentials['auth_key'], 'accept' => 'application/json'])
            ->get('https://control.msg91.com/api/v5/whatsapp/get-template-client/'.rawurlencode($credentials['integrated_number']), [
                'pagination' => 'true', 'page_size' => 500, 'page_num' => 1,
            ]);
        if ($response->failed()) {
            throw new RuntimeException("MSG91 template sync failed with status {$response->status()}.");
        }
        $rows = data_get($response->json(), 'data.templates', data_get($response->json(), 'data', []));

        return collect(is_array($rows) ? $rows : [])->filter(fn ($row) => is_array($row))->map(fn ($row) => $this->normalizeTemplate($row, $profile, 'msg91'))->filter()->values()->all();
    }

    private function validateMeta(): array
    {
        $response = $this->meta()->get('/'.setting('whatsapp_meta_phone_number_id'), ['fields' => 'display_phone_number,verified_name,quality_rating']);
        if ($response->failed()) {
            throw new RuntimeException("Meta validation failed with status {$response->status()}.");
        }

        return ['configured' => true, 'connected' => true, 'status' => $response->status(), 'account' => collect($response->json())->only(['display_phone_number', 'verified_name', 'quality_rating'])->all()];
    }

    private function metaTemplates(): array
    {
        $wabaId = setting('whatsapp_meta_business_account_id');
        if (blank($wabaId)) {
            throw new RuntimeException('Meta WhatsApp Business Account ID is required for template sync.');
        }
        $response = $this->meta()->get("/{$wabaId}/message_templates", ['fields' => 'name,status,category,language,components', 'limit' => 500]);
        if ($response->failed()) {
            throw new RuntimeException("Meta template sync failed with status {$response->status()}.");
        }

        return collect($response->json('data') ?: [])->map(fn ($row) => $this->normalizeTemplate($row, strtolower($row['category'] ?? 'utility'), 'meta'))->filter()->values()->all();
    }

    private function meta()
    {
        $version = preg_replace('/[^0-9.]/', '', (string) setting('whatsapp_meta_graph_version', '23.0')) ?: '23.0';

        return Http::withToken(setting('whatsapp_meta_access_token'))->acceptJson()->baseUrl("https://graph.facebook.com/v{$version}");
    }

    private function normalizeTemplate(array $row, string $profile, string $provider): ?array
    {
        $name = $row['name'] ?? $row['template_name'] ?? null;
        if (! is_string($name) || $name === '') {
            return null;
        }
        $components = $row['components'] ?? [];
        $text = json_encode($components);
        preg_match_all('/\{\{\s*([^}]+)\s*\}\}/', (string) $text, $matches);
        $approvalStatus = strtolower((string) ($row['status'] ?? $row['template_status'] ?? $row['approval_status'] ?? 'unknown'));

        return [
            'id' => $name, 'template_id' => $name, 'name' => $name, 'category' => strtolower($row['category'] ?? $profile),
            'event' => null, 'is_active' => $approvalStatus === 'approved', 'namespace' => $row['namespace'] ?? null,
            'language_code' => $row['language'] ?? $row['language_code'] ?? 'en',
            'variables' => array_values(array_unique($matches[1] ?? [])), 'component_keys' => [],
            'provider' => $provider, 'approval_status' => $approvalStatus, 'provider_approval_status' => $approvalStatus,
            'provider_template_id' => $row['id'] ?? $row['template_id'] ?? null,
            'provider_rejection_reason' => $row['rejected_reason'] ?? $row['rejection_reason'] ?? $row['reason'] ?? null,
            'provider_synced_at' => now()->toIso8601String(),
        ];
    }

    private function managedNexMsgAssignment(): ?WhatsAppTenantAssignment
    {
        $tenantId = app(TenantContext::class)->id();
        if (! $tenantId) {
            return null;
        }

        return WhatsAppTenantAssignment::query()->withoutGlobalTenant()->with('profile')
            ->where('tenant_id', $tenantId)->where('is_active', true)
            ->whereNull('suspended_at')->latest('id')->first();
    }

    protected function managedNexMsgCredentials(): array
    {
        $assignment = $this->managedNexMsgAssignment();
        if ($assignment?->profile?->provider !== 'nexmsg') {
            return [];
        }

        return (array) $assignment->profile->credentials;
    }

    private function profileStatus(string $profile): array
    {
        $credentials = app(Msg91Provider::class)->credentials($profile);

        return [
            'configured' => filled($credentials['auth_key']) && filled($credentials['integrated_number']),
            'integrated_number' => filled($credentials['integrated_number']) ? '••••'.substr($credentials['integrated_number'], -4) : null,
            'last_validation' => setting("whatsapp_msg91_{$profile}_last_validation"),
            'last_template_sync' => setting("whatsapp_msg91_{$profile}_last_template_sync"),
        ];
    }
}
