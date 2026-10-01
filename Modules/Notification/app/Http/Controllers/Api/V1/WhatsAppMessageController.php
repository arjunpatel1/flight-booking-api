<?php

namespace Modules\Notification\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Http\Requests\Api\V1\BulkSendWhatsAppMessageRequest;
use Modules\Notification\Http\Requests\Api\V1\RunAnniversaryOfferRequest;
use Modules\Notification\Http\Requests\Api\V1\RunBirthdayOfferRequest;
use Modules\Notification\Http\Requests\Api\V1\RunInactiveCustomerOfferRequest;
use Modules\Notification\Http\Requests\Api\V1\SendWhatsAppMessageRequest;
use Modules\Notification\Jobs\BulkSendWhatsAppMessageJob;
use Modules\Notification\Jobs\SendWhatsAppMessageJob;
use Modules\Notification\Models\WhatsAppLog;
use Modules\Notification\Services\MarketingAutomation\AnniversaryOfferService;
use Modules\Notification\Services\MarketingAutomation\BirthdayOfferService;
use Modules\Notification\Services\MarketingAutomation\InactiveCustomerOfferService;
use Modules\Notification\Services\WhatsApp\WhatsAppIntegrationService;
use Modules\Notification\Services\WhatsApp\WhatsAppProviderFactory;
use Modules\Notification\Services\WhatsApp\WhatsAppTemplateCatalog;
use Modules\Order\Models\Order;
use Modules\Support\ApiResponse;
use Modules\User\Models\Role;

class WhatsAppMessageController extends Controller
{
    public function integrationStatus(WhatsAppIntegrationService $service): JsonResponse
    {
        return ApiResponse::success($service->status());
    }

    public function testNexMsg(Request $request, WhatsAppProviderFactory $factory): JsonResponse
    {
        $catalog = collect(WhatsAppTemplateCatalog::defaults());
        $configuredTemplates = collect(setting('whatsapp_templates') ?: [])->filter(fn ($item) => is_array($item));
        $allowedTemplates = $catalog->pluck('id')
            ->merge($configuredTemplates->pluck('id'))
            ->merge($configuredTemplates->pluck('template_id'))
            ->filter()->unique()->values()->all();
        $data = $request->validate([
            'recipient' => ['required', 'regex:/^[0-9]{10,15}$/'],
            'template' => ['nullable', Rule::in($allowedTemplates)],
        ]);

        abort_unless(setting('whatsapp_provider') === 'nexmsg', 422, 'Select and save NexMsg as the WhatsApp provider first.');
        abort_unless(setting('whatsapp_enabled'), 422, 'Enable and save WhatsApp messaging first.');

        $otp = (string) random_int(100000, 999999);
        $template = $data['template'] ?? 'customer_login_otp';
        $definition = $catalog->first(fn (array $item) => in_array($template, [$item['id'] ?? null, $item['template_id'] ?? null], true));
        $configured = $configuredTemplates
            ->filter(fn (array $item) => ($item['is_active'] ?? true))
            ->first(fn (array $item) => in_array($template, [$item['id'] ?? null, $item['template_id'] ?? null], true));
        $configured ??= $configuredTemplates
            ->filter(fn (array $item) => ($item['is_active'] ?? true))
            ->first(fn (array $item) => filled($definition['event'] ?? null) && ($item['event'] ?? null) === $definition['event']);
        $providerTemplate = $configured['template_id'] ?? $configured['id'] ?? $template;
        $testOrder = Order::query()->with('branch.tenant')->latest('id')->first();
        $tenantDomain = strtolower(trim((string) $testOrder?->branch?->tenant?->domain));
        $baseUrl = filter_var($tenantDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            ? 'https://'.$tenantDomain
            : rtrim((string) (setting('frontend_url') ?: config('app.url')), '/');
        $testReference = (string) ($testOrder?->reference_no ?: 'TEST-'.now()->format('YmdHis'));
        $testOrderTarget = $testOrder
            ? $baseUrl.'/admin/orders/'.$testOrder->id.'/show'
            : $baseUrl.'/admin/orders';
        $result = $factory->make(\Modules\Notification\Enums\WhatsAppProvider::NexMsg)->sendTemplate(
            $data['recipient'],
            $providerTemplate,
            [
                'otp' => $otp,
                'button_code' => $otp,
                'restaurant_name' => (string) (setting('app_name') ?: config('app.name', 'NexDine')),
                'customer_name' => 'Notification Test',
                'order_id' => $testReference,
                'order_number' => $testReference,
                'order_total' => 'INR 1.00',
                'order_total_numeric' => '1.00',
                'estimated_time' => '20 minutes',
                'payment_token' => 'test-payment-token',
                'order_date' => now()->format('d/m/Y H:i'),
                'delivered_at' => now()->format('d/m/Y H:i'),
                'invoice_number' => 'TEST-INVOICE',
                'reason' => 'Notification test',
                'greeting' => 'This is a NexDine notification test.',
                'bill_total' => 'INR 1.00',
                'amount' => 'INR 1.00',
                'source' => 'Template Test',
                'items' => 'Test item x 1',
                'item_quantity' => '1',
                'item_qty' => '1',
                'iteam_qty' => '1',
                'business_name' => (string) (setting('app_name') ?: config('app.name', 'NexDine')),
                'branch_name' => (string) (setting('app_name') ?: config('app.name', 'NexDine')),
                'payment_status' => 'Paid',
                'fulfilment' => 'Delivery',
                'ordered_at' => now()->format('d/m/Y H:i'),
                'delivery_status' => 'Rider assigned - test update',
                'tracking_link' => $baseUrl.'/online-menu/test/orders/'.$testReference,
                'cancel_link' => $baseUrl.'/v1/customer-app/order-cancel/test-token',
                'cancel_token' => 'test-token',
                'payment_link' => $baseUrl.'/invoices/test-invoice',
                'feedback_link' => $baseUrl.'/feedback/orders/'.$testReference.'?source=whatsapp&token=test-token',
                'rating_link' => $baseUrl.'/feedback/orders/'.$testReference.'?source=whatsapp&token=test-token',
                'order_again_link' => $baseUrl.'/online-menu/test',
                'order_link' => $testOrderTarget,
                'feedback_reply' => 'happy:'.$testReference,
                'booking_date' => now()->addDay()->format('d M Y'),
                'booking_time' => '7:30 PM',
                'table_name' => 'Table 5',
                'offer_title' => 'Template preview offer',
                'coupon_code' => 'TEST20',
                'valid_until' => now()->addMonth()->format('d M Y'),
                'discount_value' => '20%',
                'gift_name' => 'Template preview gift',
                'reward_name' => 'Template preview reward',
                'points_balance' => '100',
            ],
        );

        return ApiResponse::success([
            'accepted' => true,
            'provider' => 'nexmsg',
            'recipient' => $data['recipient'],
            'template' => $result['template'] ?? $providerTemplate,
            'catalog_template' => $template,
            'provider_status' => $result['status'] ?? null,
            'provider_response' => $result['response'] ?? null,
        ]);
    }

    public function validateIntegration(Request $request, WhatsAppIntegrationService $service): JsonResponse
    {
        $data = $request->validate(['provider' => ['required', 'in:msg91,meta'], 'profile' => ['nullable', 'in:utility,marketing']]);

        return ApiResponse::success($service->validate($data['provider'], $data['profile'] ?? null));
    }

    public function syncTemplates(Request $request, WhatsAppIntegrationService $service): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:msg91,nexmsg,meta'],
            'profile' => ['nullable', 'in:utility,marketing'],
            'catalog' => ['nullable', 'array'],
            'catalog.*' => ['array'],
            'status_only' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success($service->syncTemplates($data['provider'], $data['profile'] ?? null, $data['catalog'] ?? [], (bool) ($data['status_only'] ?? false)));
    }

    public function meta(): JsonResponse
    {
        return ApiResponse::success([
            'templates' => collect(setting('whatsapp_templates') ?: [])->filter(function ($template) {
                return is_string($template) || ($template['is_active'] ?? true);
            })->map(function ($template) {
                if (is_string($template)) {
                    return [
                        'id' => $template,
                        'template_id' => $template,
                        'name' => $template,
                        'category' => null,
                        'event' => null,
                        'namespace' => null,
                        'language_code' => 'en',
                        'component_keys' => [],
                    ];
                }

                $id = $template['id'] ?? $template['name'] ?? $template['template'] ?? null;

                return [
                    'id' => $id,
                    'template_id' => $template['template_id'] ?? $id,
                    'name' => $template['name'] ?? $template['label'] ?? $id,
                    'description' => $template['description'] ?? null,
                    'message' => $template['message'] ?? null,
                    'category' => $template['category'] ?? null,
                    'event' => $template['event'] ?? null,
                    'namespace' => $template['namespace'] ?? null,
                    'language_code' => $template['language_code'] ?? 'en',
                    'variables' => $template['variables'] ?? [],
                    'component_keys' => $template['component_keys'] ?? [],
                ];
            })->filter(fn ($template) => filled($template['id']))->values(),
            'roles' => Role::list(withCustomer: true),
            'audiences' => BulkSendWhatsAppMessageJob::audienceOptions(),
            'dynamic_parameters' => BulkSendWhatsAppMessageJob::DYNAMIC_PARAMETERS,
        ]);
    }

    public function direct(SendWhatsAppMessageRequest $request): JsonResponse
    {
        SendWhatsAppMessageJob::dispatch(
            $request->string('recipient')->toString(),
            $request->string('template')->toString(),
            $request->input('parameters', []),
        );

        return ApiResponse::success(['success' => true]);
    }

    public function bulk(BulkSendWhatsAppMessageRequest $request): JsonResponse
    {
        $campaignId = (string) Str::uuid();
        $scheduledAt = $request->filled('scheduled_at')
            ? Carbon::parse($request->input('scheduled_at'))
            : null;

        $dispatch = BulkSendWhatsAppMessageJob::dispatch(
            $request->string('audience')->toString(),
            $request->string('template')->toString(),
            $request->input('parameters', []),
            $request->input('role_names'),
            $request->input('recipient_ids'),
            $campaignId,
            $scheduledAt?->toDateTimeString(),
        );

        if ($scheduledAt) {
            $dispatch->delay($scheduledAt);
        }

        return ApiResponse::success([
            'success' => true,
            'campaign_id' => $campaignId,
            'scheduled_at' => $scheduledAt ? dateTimeFormat($scheduledAt) : null,
        ]);
    }

    public function audiencePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['bail', 'required', Rule::in(BulkSendWhatsAppMessageJob::audienceKeys())],
            'role_names' => ['required_if:audience,roles', 'nullable', 'array'],
            'role_names.*' => ['string', 'exists:roles,name'],
            'recipient_ids' => ['nullable', 'array'],
            'recipient_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $job = new BulkSendWhatsAppMessageJob(
            audience: $data['audience'],
            template: 'audience_preview',
            roleNames: $data['role_names'] ?? null,
            recipientIds: $data['recipient_ids'] ?? null,
        );

        return ApiResponse::success([
            'audience' => $data['audience'],
            'audience_label' => __("notification::notifications.audiences.{$data['audience']}"),
            'estimated_recipients' => $job->recipientCount(),
            'has_manual_recipients' => filled($data['recipient_ids'] ?? null),
        ]);
    }

    public function runInactiveCustomerOffer(
        RunInactiveCustomerOfferRequest $request,
        InactiveCustomerOfferService $service,
    ): JsonResponse {
        $result = $service->queue(
            template: $request->input('template', 'inactive_customer_offer'),
            couponCode: $request->input('coupon_code'),
            validUntil: $request->input('valid_until'),
            force: (bool) $request->boolean('force'),
        );

        return ApiResponse::success([
            'success' => true,
            ...$result,
        ]);
    }

    public function runBirthdayOffer(
        RunBirthdayOfferRequest $request,
        BirthdayOfferService $service,
    ): JsonResponse {
        $result = $service->queue(
            template: $request->input('template', 'birthday_offer'),
            couponCode: $request->input('coupon_code'),
            force: (bool) $request->boolean('force'),
        );

        return ApiResponse::success([
            'success' => true,
            ...$result,
        ]);
    }

    public function runAnniversaryOffer(
        RunAnniversaryOfferRequest $request,
        AnniversaryOfferService $service,
    ): JsonResponse {
        $result = $service->queue(
            template: $request->input('template', 'anniversary_offer'),
            couponCode: $request->input('coupon_code'),
            force: (bool) $request->boolean('force'),
        );

        return ApiResponse::success(['success' => true, ...$result]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $provider = setting('whatsapp_provider') ?: 'msg91';

        try {
            $payload = $request->all();
            setting(['whatsapp_webhook_last_received_at' => now()->toIso8601String(), 'whatsapp_webhook_last_provider' => $provider]);

            // Never log recipient data, provider signatures or message
            // content. Operational diagnostics only need the provider and
            // stable event keys.
            \Log::info('WhatsApp webhook received', [
                'provider' => $provider,
                'event_keys' => array_keys($payload),
            ]);

            // Handle delivery status updates
            if (isset($payload['status']) && isset($payload['message_id'])) {
                $log = WhatsAppLog::where('response_payload->message_id', $payload['message_id'])
                    ->orWhere('response_payload->id', $payload['message_id'])
                    ->first();

                if ($log) {
                    $log->update([
                        'delivery_status' => $payload['status'],
                        'delivered_at' => isset($payload['delivered_at']) ? now()->createFromTimestamp($payload['delivered_at']) : null,
                        'response_payload' => array_merge($log->response_payload ?? [], ['webhook' => $payload]),
                    ]);
                }
            }

            return ApiResponse::success(['received' => true]);
        } catch (\Throwable $exception) {
            \Log::error('WhatsApp Webhook Error', [
                'provider' => $provider,
                'error' => $exception->getMessage(),
                'payload' => $request->all(),
            ]);

            return ApiResponse::error('Webhook processing failed', 500);
        }
    }
}
