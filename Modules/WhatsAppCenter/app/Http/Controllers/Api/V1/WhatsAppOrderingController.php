<?php

namespace Modules\WhatsAppCenter\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Payment\Models\TenantPaymentGatewayConfig;
use Modules\Payment\Services\DirectUpiService;
use Modules\Product\Models\Product;
use Modules\Support\ApiResponse;
use Modules\WhatsAppCenter\Exceptions\WhatsAppContextException;
use Modules\WhatsAppCenter\Jobs\ProcessWhatsAppOrderingMessage;
use Modules\WhatsAppCenter\Jobs\SyncWhatsAppCatalogProduct;
use Modules\WhatsAppCenter\Models\WhatsAppCatalogProduct;
use Modules\WhatsAppCenter\Models\WhatsAppConversation;
use Modules\WhatsAppCenter\Models\WhatsAppMessage;
use Modules\WhatsAppCenter\Models\WhatsAppOrderSession;
use Modules\WhatsAppCenter\Models\WhatsAppPhoneNumber;
use Modules\WhatsAppCenter\Models\WhatsAppProviderProfile;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use Modules\WhatsAppCenter\Models\WhatsAppWebhookEvent;
use Modules\WhatsAppCenter\Services\NexMsgConnectionValidator;
use Modules\WhatsAppCenter\Services\WhatsAppBranchTransition;
use Modules\WhatsAppCenter\Services\WhatsAppChannelContextResolver;
use Modules\WhatsAppCenter\Services\WhatsAppConnectionDiagnostics;
use Modules\WhatsAppCenter\Services\WhatsAppOrderingEngine;
use Modules\WhatsAppCenter\Services\WhatsAppOrderingProviderFactory;
use Modules\WhatsAppCenter\Services\WhatsAppWebhookRejectionRecorder;
use Modules\WhatsAppCenter\Transformers\Api\V1\WhatsAppConnectionResource;
use Modules\WhatsAppCenter\Transformers\Api\V1\WhatsAppConversationResource;
use Modules\WhatsAppCenter\Transformers\Api\V1\WhatsAppOrderSessionResource;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class WhatsAppOrderingController extends Controller
{
    public function __construct(
        private readonly WhatsAppChannelContextResolver $contexts,
        private readonly WhatsAppOrderingProviderFactory $providers,
        private readonly NexMsgConnectionValidator $nexMsgValidator,
        private readonly WhatsAppWebhookRejectionRecorder $rejections,
        private readonly WhatsAppBranchTransition $branchTransition,
    ) {}

    public function verifyProviderWebhook(Request $request)
    {
        $expected = (string) config('whatsappcenter.webhooks.meta_verify_token');
        $supplied = (string) $request->query('hub_verify_token');

        abort_unless($request->query('hub_mode') === 'subscribe', 422, 'WHATSAPP_INVALID_CHALLENGE');
        abort_unless($expected !== '' && hash_equals($expected, $supplied), 403, 'WHATSAPP_INVALID_CHALLENGE');

        return response((string) $request->query('hub_challenge'), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function providerWebhook(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, ['meta', 'msg91', 'nexmsg'], true), 404);
        $this->assertWebhookEnvelope($request);

        $adapter = $this->providers->make($provider);
        $message = $adapter->normalizeInbound($request);

        try {
            $profile = $this->contexts->resolveProfileForProviderPhone($provider, $message->providerPhoneId);
        } catch (WhatsAppContextException $exception) {
            Log::warning('Shared WhatsApp webhook account lookup rejected.', [
                'provider' => $provider,
                'error_code' => $exception->errorCode,
            ]);

            // Keep account existence and duplicate-configuration details out of
            // the public response. Operators retain the precise reason in logs.
            return ApiResponse::errors(
                ['code' => 'WHATSAPP_WEBHOOK_REJECTED'],
                'The webhook could not be authenticated.',
                401,
            );
        }

        return $this->webhook($request, (string) $profile->uuid);
    }

    public function verifyWebhook(Request $request, string $profile)
    {
        try {
            $provider = $this->contexts->resolveProfile($profile);
            $challenge = $this->providers->make($provider->provider)->verifyChallenge($request, $provider);

            return response($challenge, 200)->header('Content-Type', 'text/plain');
        } catch (WhatsAppContextException $exception) {
            return ApiResponse::errors(['code' => $exception->errorCode], $exception->getMessage(), $exception->getCode());
        }
    }

    public function webhook(Request $request, string $profile): JsonResponse
    {
        $this->assertWebhookEnvelope($request);
        $raw = $request->getContent();
        $correlationId = Str::isUuid((string) $request->header('X-Request-Id'))
            ? (string) $request->header('X-Request-Id') : (string) Str::uuid();

        $message = null;
        try {
            $provider = $this->contexts->resolveProfile($profile);
            $adapter = $this->providers->make($provider->provider);
            try {
                $adapter->verifyWebhook($request, $provider);
            } catch (HttpException $exception) {
                $this->rejections->invalidSignature($provider);
                throw $exception;
            }
            $message = $adapter->normalizeInbound($request);
            abort_unless($message->providerPhoneId !== '' && $message->providerEventId !== '' && $message->sender !== '', 422, 'WHATSAPP_INVALID_EVENT');
            abort_unless(in_array($message->type, ['text', 'button', 'interactive', 'order', 'location'], true), 422, 'WHATSAPP_UNSUPPORTED_EVENT');
            if ($message->type === 'location') {
                abort_unless(\Modules\Order\Delivery\DeliveryLocation::fromAddress($message->location ?? []), 422, 'WHATSAPP_INVALID_LOCATION');
            }
            if ($message->type === 'order') {
                abort_unless($message->catalogId && $message->orderItems !== [], 422, 'WHATSAPP_INVALID_ORDER');
                abort_if(collect($message->orderItems)->contains(fn (array $item) => blank($item['product_retailer_id'] ?? null)
                    || ($item['quantity'] ?? 0) < 1 || ($item['quantity'] ?? 0) > 99), 422, 'WHATSAPP_INVALID_ORDER');
            }
            $providerTimestamp = data_get($message->safePayload, 'timestamp');
            if ($message->type === 'location') {
                abort_unless(is_numeric($providerTimestamp), 422, 'WHATSAPP_LOCATION_TIMESTAMP_REQUIRED');
            }
            if (is_numeric($providerTimestamp)) {
                abort_if(abs(now()->timestamp - (int) $providerTimestamp) > 300, 409, 'WHATSAPP_EVENT_EXPIRED');
            }
            $context = $this->contexts->resolve($profile, $message->providerPhoneId, $correlationId);
        } catch (WhatsAppContextException $exception) {
            Log::warning('WhatsApp webhook context rejected.', ['correlation_id' => $correlationId, 'error_code' => $exception->errorCode]);
            if ($message !== null && isset($provider)) {
                // The signature was verified, so the event is genuine: surface
                // the setup problem to the restaurant and platform admins.
                $this->rejections->contextRejected($provider, $message, $exception->errorCode, $correlationId);
            }

            return ApiResponse::errors(['code' => $exception->errorCode, 'correlation_id' => $correlationId], $exception->getMessage(), $exception->getCode());
        }

        DB::transaction(function () use ($raw, $context, $message) {
            $payloadHash = $message->type === 'order'
                ? hash('sha256', json_encode([
                    'sender' => $message->sender,
                    'catalog_id' => $message->catalogId,
                    'items' => $message->orderItems,
                ], JSON_THROW_ON_ERROR))
                : hash('sha256', $raw);
            if ($message->type === 'order' && $message->providerOrderId) {
                $existingOrderEvent = WhatsAppWebhookEvent::query()->withoutGlobalTenant()
                    ->where('provider_profile_id', $context->providerProfileId)
                    ->where('provider_order_id', $message->providerOrderId)
                    ->first();
                if ($existingOrderEvent) {
                    abort_unless(hash_equals((string) $existingOrderEvent->payload_hash, $payloadHash), 409, 'WHATSAPP_REPLAY_CONFLICT');

                    return;
                }
            }
            $event = WhatsAppWebhookEvent::query()->withoutGlobalTenant()->firstOrCreate(
                ['provider_profile_id' => $context->providerProfileId, 'provider_event_id' => $message->providerEventId],
                ['tenant_id' => $context->tenantId, 'event_type' => $message->type === 'order' ? 'order' : 'message',
                    'provider_order_id' => $message->providerOrderId, 'payload_hash' => $payloadHash, 'payload' => [
                        ...$message->safePayload,
                        'provider_phone_id' => $message->providerPhoneId,
                        'provider_event_id' => $message->providerEventId,
                        'sender_masked' => $this->maskPhone($message->sender),
                        'type' => $message->type,
                        'text' => $message->text,
                        'catalog_id' => $message->catalogId,
                        'order_items' => $message->orderItems,
                        // Coordinates are kept on the scoped message for the worker,
                        // never in the general webhook audit payload.
                    ], 'status' => 'queued']
            );
            if (! $event->wasRecentlyCreated) {
                abort_unless(hash_equals((string) $event->payload_hash, $payloadHash), 409, 'WHATSAPP_REPLAY_CONFLICT');

                return;
            }
            $conversation = WhatsAppConversation::query()->withoutGlobalTenant()->firstOrCreate(
                ['tenant_id' => $context->tenantId, 'assignment_id' => $context->assignmentId, 'customer_phone' => $message->sender, 'closed_at' => null],
                ['uuid' => (string) Str::uuid(), 'branch_id' => $context->branchId, 'state' => 'bot']
            );
            $inbound = WhatsAppMessage::query()->withoutGlobalTenant()->firstOrCreate(
                ['conversation_id' => $conversation->id, 'provider_message_id' => $message->providerEventId],
                ['tenant_id' => $context->tenantId, 'direction' => 'inbound', 'type' => $message->type,
                    'body' => $message->text, 'payload' => [
                        ...$message->safePayload,
                        'catalog_id' => $message->catalogId,
                        'order_items' => $message->orderItems,
                        'location' => $message->location,
                    ], 'status' => 'received']
            );
            $conversation->update(['last_message_at' => now()]);
            $context->profile->update(['webhook_last_received_at' => now(), 'status' => 'connected', 'last_error' => null]);
            if ($inbound->wasRecentlyCreated) {
                ProcessWhatsAppOrderingMessage::dispatch($context->tenantId, $conversation->id, $inbound->id, $event->id)->afterCommit();
            }
        });

        return ApiResponse::success(['accepted' => true, 'correlation_id' => $correlationId]);
    }

    private function assertWebhookEnvelope(Request $request): void
    {
        abort_if(strlen($request->getContent()) > 1_048_576, 413, 'WHATSAPP_PAYLOAD_TOO_LARGE');
        abort_unless($request->isJson(), 415, 'WHATSAPP_JSON_REQUIRED');
    }

    public function overview(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $assignment = WhatsAppTenantAssignment::query()->with(['profile', 'phoneNumber'])
            ->where('tenant_id', $tenantId)->where('is_active', true)->latest('id')->first();
        $catalogQuery = WhatsAppCatalogProduct::query()->where('tenant_id', $tenantId);
        $allowedBranchIds = collect($assignment?->allowed_branch_ids ?? [])->map(fn ($id) => (int) $id)->filter()->unique();
        if ($assignment) {
            $catalogQuery->where('provider_profile_id', $assignment->provider_profile_id)
                ->when($allowedBranchIds->isNotEmpty(), fn ($query) => $query->whereIn('branch_id', $allowedBranchIds));
        }
        $catalogId = trim((string) data_get($assignment?->profile?->credentials, 'catalog_id'));
        $paymentReadiness = $this->paymentGatewayReadiness($tenantId);

        return ApiResponse::success([
            'webhook_urls' => $this->webhookUrls(),
            'connection' => $assignment ? (new WhatsAppConnectionResource($assignment))->resolve($request) : null,
            'capabilities' => $assignment?->capabilities ?? [],
            'payment_readiness' => [
                'ready' => $paymentReadiness['ready'],
                'provider' => $paymentReadiness['provider'],
                'message' => $paymentReadiness['message'],
            ],
            'catalog' => [
                'catalog_id' => $catalogId ?: null,
                'total' => (clone $catalogQuery)->count(),
                'pending' => (clone $catalogQuery)->whereIn('sync_status', ['pending', 'syncing'])->count(),
                'synced' => (clone $catalogQuery)->where('sync_status', 'synced')->count(),
                'disabled' => (clone $catalogQuery)->where('sync_status', 'disabled')->count(),
                'failed' => (clone $catalogQuery)->where('sync_status', 'failed')->count(),
                'last_synced_at' => (clone $catalogQuery)->max('last_synced_at'),
                'last_error' => (clone $catalogQuery)->where('sync_status', 'failed')->latest('updated_at')->value('last_sync_error'),
            ],
            'available_branches' => Branch::query()->where('tenant_id', $tenantId)->where('is_active', true)
                ->when($allowedBranchIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $allowedBranchIds))
                ->orderBy('name')->get()->map(fn ($branch) => ['uuid' => $branch->uuid, 'name' => $branch->name])->values(),
            'counts' => [
                'open_conversations' => WhatsAppConversation::query()->where('tenant_id', $tenantId)->whereNull('closed_at')->count(),
                'waiting_for_staff' => WhatsAppConversation::query()->where('tenant_id', $tenantId)->where('state', 'waiting_for_staff')->count(),
                'active_order_sessions' => WhatsAppOrderSession::query()->where('tenant_id', $tenantId)->whereNotIn('state', ['completed', 'cancelled', 'expired'])->count(),
                'queued_webhooks' => WhatsAppWebhookEvent::query()->where('tenant_id', $tenantId)->where('status', 'queued')->count(),
                'failed_webhooks' => WhatsAppWebhookEvent::query()->where('tenant_id', $tenantId)->where('status', 'failed')->count(),
            ],
        ]);
    }

    public function diagnostics(Request $request, WhatsAppConnectionDiagnostics $diagnostics): JsonResponse
    {
        $assignment = WhatsAppTenantAssignment::query()->with('profile')
            ->where('tenant_id', $this->tenantId($request))->latest('is_active')->latest('id')->first();
        abort_unless($assignment?->profile, 404, 'No WhatsApp number is connected to this restaurant yet.');

        return ApiResponse::success($diagnostics->forProfile($assignment->profile));
    }

    public function syncCatalogMappings(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'branch_uuid' => ['required', 'uuid'],
            'catalog_id' => ['required', 'string', 'max:191'],
            'product_uuids' => ['nullable', 'array', 'max:500'],
            'product_uuids.*' => ['uuid'],
        ]);
        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->with('profile')
            ->where('tenant_id', $tenantId)->where('is_active', true)->whereNull('suspended_at')->firstOrFail();
        $branch = Branch::query()->withoutGlobalActive()->where('tenant_id', $tenantId)
            ->where('uuid', $data['branch_uuid'])->where('is_active', true)->firstOrFail();
        $allowedBranchIds = array_map('intval', $assignment->allowed_branch_ids ?: []);
        abort_unless($allowedBranchIds === [] || in_array($branch->id, $allowedBranchIds, true), 403, 'WHATSAPP_BRANCH_UNAVAILABLE');
        abort_unless($assignment->profile, 409, 'The assigned WhatsApp connection is unavailable. Ask the platform administrator to reconnect it.');
        $configuredCatalog = trim((string) data_get($assignment->profile->credentials, 'catalog_id'));
        abort_unless($configuredCatalog !== '' && hash_equals($configuredCatalog, $data['catalog_id']), 422, 'WHATSAPP_CATALOG_MISMATCH');

        $products = Product::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('is_active', true)->where('is_available', true)
            ->when($data['product_uuids'] ?? [], fn ($query, $ids) => $query->whereIn('uuid', $ids))
            ->whereHas('menu', fn ($query) => $query->withoutGlobalScopes()->where('menus.branch_id', $branch->id)
                ->where('menus.is_active', true)->whereNull('menus.deleted_at'))
            ->without('branch')
            ->get(['id', 'uuid']);
        $mappingIds = DB::transaction(function () use ($products, $assignment, $branch, $tenantId, $configuredCatalog, $data): array {
            $mappingIds = [];
            foreach ($products as $product) {
                $mapping = WhatsAppCatalogProduct::query()->withoutGlobalTenant()->firstOrNew(
                    ['provider_profile_id' => $assignment->provider_profile_id, 'catalog_id' => $configuredCatalog, 'product_id' => $product->id]
                );
                // Meta carts use the retailer ID that was published when the customer
                // opened the catalog. Never replace a manually matched ID on sync.
                if (! $mapping->exists || blank($mapping->product_retailer_id)) {
                    $mapping->product_retailer_id = (string) $product->uuid;
                }
                $mapping->fill(['tenant_id' => $tenantId, 'branch_id' => $branch->id,
                    'provider' => $assignment->profile->provider, 'status' => 'active', 'sync_status' => 'pending']);
                $mapping->save();
                $mappingIds[] = $mapping->id;
            }

            if (empty($data['product_uuids'])) {
                $disabled = WhatsAppCatalogProduct::query()->withoutGlobalTenant()
                    ->where('tenant_id', $tenantId)->where('branch_id', $branch->id)
                    ->where('provider_profile_id', $assignment->provider_profile_id)
                    ->where('catalog_id', $configuredCatalog)
                    ->whereNotIn('product_id', $products->pluck('id'))->get();
                foreach ($disabled as $mapping) {
                    $mapping->update(['status' => 'disabled', 'sync_status' => 'pending']);
                    $mappingIds[] = $mapping->id;
                }
            }

            return array_values(array_unique($mappingIds));
        });
        foreach ($mappingIds as $mappingId) {
            SyncWhatsAppCatalogProduct::dispatch($mappingId)->afterCommit();
        }

        return ApiResponse::success(['catalog_id' => $configuredCatalog, 'mapped_products' => $products->count(), 'queued_products' => count($mappingIds)]);
    }

    public function catalogMappings(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'branch_uuid' => ['required', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['synced', 'attention', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->with('profile')
            ->where('tenant_id', $tenantId)->where('is_active', true)->whereNull('suspended_at')->firstOrFail();
        $branch = Branch::query()->withoutGlobalActive()->where('tenant_id', $tenantId)
            ->where('uuid', $data['branch_uuid'])->where('is_active', true)->firstOrFail();
        $this->assertAssignmentPermitsBranch($assignment, $branch);
        abort_unless($assignment->profile, 409, 'The assigned WhatsApp connection is unavailable. Ask the platform administrator to reconnect it.');
        $credentials = is_array($assignment->profile->credentials) ? $assignment->profile->credentials : [];
        $catalogId = trim((string) ($credentials['catalog_id'] ?? ''));

        $products = Product::query()->withoutGlobalScopes()
            ->with(['menu', 'branch', 'files'])
            ->whereNull('deleted_at')
            ->where('is_active', true)->where('is_available', true)
            ->whereHas('menu', fn ($query) => $query->withoutGlobalScopes()->where('menus.branch_id', $branch->id)
                ->where('menus.is_active', true)->whereNull('menus.deleted_at'))
            ->when(trim((string) ($data['search'] ?? '')), fn ($query, $search) => $query
                ->where(fn ($product) => $product->where('name', 'like', '%'.$search.'%')->orWhere('uuid', $search)))
            ->orderBy('name')->get();
        $mappings = WhatsAppCatalogProduct::query()->withoutGlobalTenant()
            ->where('tenant_id', $tenantId)->where('branch_id', $branch->id)
            ->where('provider_profile_id', $assignment->provider_profile_id)->where('catalog_id', $catalogId)
            ->whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id');

        $status = $data['status'] ?? 'synced';
        $rows = $products->map(function (Product $product) use ($mappings): array {
            $mapping = $mappings->get($product->id);

            return [
                'product_uuid' => $product->uuid,
                'menu_id' => $product->menu_id,
                'product_name' => (string) $product->name,
                'description' => trim(strip_tags((string) $product->description)),
                'menu_name' => (string) ($product->menu?->name ?: ''),
                'image_url' => $this->catalogProductImageUrl($product),
                'price' => (float) $product->selling_price->amount(),
                'currency' => (string) $product->branch->currency,
                'retailer_id' => $mapping?->product_retailer_id,
                'provider_product_id' => $mapping?->provider_product_id,
                'status' => $mapping?->status ?: 'unmapped',
                'sync_status' => $mapping?->sync_status ?: 'unmapped',
                'last_synced_at' => $mapping?->last_synced_at,
                'last_error' => $mapping?->last_sync_error,
            ];
        })->when($status === 'synced', fn ($items) => $items->where('sync_status', 'synced'))
            ->when($status === 'attention', fn ($items) => $items->whereIn('sync_status', ['unmapped', 'pending', 'failed']))
            ->values();
        $perPage = (int) ($data['per_page'] ?? 25);
        $page = max(1, (int) $request->integer('page', 1));

        return ApiResponse::success([
            'catalog_id' => $catalogId ?: null,
            'configuration_message' => $catalogId === '' ? 'The assigned WhatsApp connection needs a Meta Catalog ID before products can sync.' : null,
            'branch' => ['uuid' => $branch->uuid, 'name' => $branch->name],
            'products' => $rows->forPage($page, $perPage)->values(),
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))],
        ]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()
            ->where('tenant_id', $this->tenantId($request))->where('is_active', true)->latest('id')->firstOrFail();

        DB::transaction(function () use ($assignment): void {
            $assignment->update(['is_active' => false, 'suspended_at' => now()]);
            $assignment->phoneNumber?->update(['status' => 'disconnected']);
            $assignment->profile?->update(['status' => 'disconnected']);
        });

        return ApiResponse::success(null, 'WhatsApp connection disconnected. Existing orders and audit logs were preserved.');
    }

    public function saveCatalogMapping(Request $request, string $productUuid): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'branch_uuid' => ['required', 'uuid'],
            'retailer_id' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);
        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->with('profile')
            ->where('tenant_id', $tenantId)->where('is_active', true)->whereNull('suspended_at')->firstOrFail();
        $branch = Branch::query()->withoutGlobalActive()->where('tenant_id', $tenantId)
            ->where('uuid', $data['branch_uuid'])->where('is_active', true)->firstOrFail();
        $this->assertAssignmentPermitsBranch($assignment, $branch);
        abort_unless($assignment->profile, 409, 'The assigned WhatsApp connection is unavailable. Ask the platform administrator to reconnect it.');
        $catalogId = trim((string) data_get($assignment->profile->credentials, 'catalog_id'));
        abort_if($catalogId === '', 422, 'WHATSAPP_CATALOG_NOT_CONFIGURED');
        $product = Product::query()->withoutGlobalScopes()->where('uuid', $productUuid)
            ->whereNull('deleted_at')->where('is_active', true)->where('is_available', true)
            ->whereHas('menu', fn ($query) => $query->withoutGlobalScopes()->where('menus.branch_id', $branch->id)
                ->where('menus.is_active', true)->whereNull('menus.deleted_at'))->firstOrFail();
        $retailerId = trim($data['retailer_id']);
        $collision = WhatsAppCatalogProduct::query()->withoutGlobalTenant()
            ->where('provider_profile_id', $assignment->provider_profile_id)->where('catalog_id', $catalogId)
            ->where('product_retailer_id', $retailerId)->where('product_id', '!=', $product->id)->exists();
        abort_if($collision, 422, 'WHATSAPP_RETAILER_ID_ALREADY_MAPPED');

        $mapping = WhatsAppCatalogProduct::query()->withoutGlobalTenant()->updateOrCreate(
            ['provider_profile_id' => $assignment->provider_profile_id, 'catalog_id' => $catalogId, 'product_id' => $product->id],
            ['tenant_id' => $tenantId, 'branch_id' => $branch->id, 'provider' => $assignment->profile->provider,
                'product_retailer_id' => $retailerId, 'provider_product_id' => null, 'payload_hash' => null,
                'status' => 'active', 'sync_status' => 'pending', 'last_sync_error' => null]
        );
        SyncWhatsAppCatalogProduct::dispatch($mapping->id)->afterCommit();

        return ApiResponse::success(['product_uuid' => $product->uuid, 'retailer_id' => $retailerId,
            'sync_status' => 'pending'], 'Product mapping saved and queued for synchronization.');
    }

    /** @return array{ready: bool, provider: ?string, message: string} */
    private function paymentGatewayReadiness(int $tenantId): array
    {
        $gateway = TenantPaymentGatewayConfig::query()->withoutGlobalTenant()
            ->where('tenant_id', $tenantId)
            ->whereIn('provider', ['direct_upi', 'razorpay'])
            ->where('enabled', true)
            ->where('last_test_status', 'configured')
            ->orderByRaw("CASE WHEN provider = 'direct_upi' THEN 0 ELSE 1 END")
            ->first();

        if ($gateway) {
            $label = $gateway->provider === 'direct_upi' ? 'Direct UPI' : 'Razorpay';

            return [
                'ready' => true,
                'provider' => $gateway->provider,
                'message' => $label.' is tested and ready for secure WhatsApp payment links.',
            ];
        }

        return [
            'ready' => false,
            'provider' => null,
            'message' => 'Test and enable Direct UPI or Razorpay in Payment Gateways before enabling WhatsApp payment links.',
        ];
    }

    private function assertAssignmentPermitsBranch(WhatsAppTenantAssignment $assignment, Branch $branch): void
    {
        $allowedBranchIds = array_map('intval', $assignment->allowed_branch_ids ?: []);
        abort_unless($allowedBranchIds === [] || in_array((int) $branch->id, $allowedBranchIds, true), 403, 'WHATSAPP_BRANCH_UNAVAILABLE');
    }

    private function webhookUrls(): array
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return [
            ['provider' => 'meta', 'label' => 'Meta Cloud API', 'url' => $baseUrl.'/v1/whatsapp/webhook/meta', 'methods' => ['GET', 'POST'], 'purpose' => 'Verification and ordering events'],
            ['provider' => 'msg91', 'label' => 'MSG91', 'url' => $baseUrl.'/v1/whatsapp/webhook/msg91', 'methods' => ['POST'], 'purpose' => 'Ordering events'],
            ['provider' => 'nexmsg', 'label' => 'NexMsg', 'url' => $baseUrl.'/v1/whatsapp/webhook/nexmsg', 'methods' => ['POST'], 'purpose' => 'Ordering events and catalog greeting'],
        ];
    }

    public function connect(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'provider' => ['required', Rule::in(['meta', 'msg91', 'nexmsg'])],
            'name' => ['required', 'string', 'max:120'],
            'display_number' => ['required', 'string', 'max:40'],
            'provider_phone_id' => ['required', 'string', 'max:191'],
            'credentials' => ['required', 'array'],
            'credentials.access_token' => ['required_if:provider,meta', 'nullable', 'string', 'max:4096'],
            'credentials.business_account_id' => ['required_if:provider,meta', 'nullable', 'string', 'max:191'],
            'credentials.auth_key' => [Rule::requiredIf(fn () => in_array($request->input('provider'), ['msg91', 'nexmsg'], true)), 'nullable', 'string', 'max:4096'],
            'credentials.account_id' => ['required_if:provider,nexmsg', 'nullable', 'string', 'regex:/^[a-f0-9]{24}$/i'],
            'credentials.catalog_id' => ['required_if:provider,nexmsg', 'nullable', 'string', 'max:191'],
            'credentials.webhook_secret' => ['required', 'string', 'min:16', 'max:4096'],
            'allowed_branch_ids' => ['nullable', 'array'],
            'allowed_branch_ids.*' => ['string', 'max:64'],
        ]);
        $this->nexMsgValidator->validate($data);

        $branchIds = $this->resolveBranchIds($tenantId, $data['allowed_branch_ids'] ?? []);
        // Two active profiles with one provider number ID make every inbound
        // webhook ambiguous, so the number can belong to one active profile.
        $ownedElsewhere = WhatsAppPhoneNumber::query()
            ->where('provider_phone_id', $data['provider_phone_id'])->where('is_active', true)
            ->whereHas('profile', fn ($query) => $query->withoutGlobalTenant()->where('provider', $data['provider'])
                ->where('is_active', true)->whereNotIn('status', ['disabled', 'suspended', 'revoked'])
                ->where(fn ($owner) => $owner->whereNull('tenant_id')->orWhere('tenant_id', '!=', $tenantId)))
            ->exists();
        if ($ownedElsewhere) {
            throw ValidationException::withMessages([
                'provider_phone_id' => 'This WhatsApp number is already connected through NexDine or another restaurant. Ask the platform administrator to release it first.',
            ]);
        }

        $assignment = DB::transaction(function () use ($data, $tenantId, $branchIds) {
            // Retire this restaurant's earlier profile for the same number.
            WhatsAppProviderProfile::query()->where('tenant_id', $tenantId)->where('provider', $data['provider'])
                ->where('ownership_mode', 'restaurant_owned')
                ->whereHas('phoneNumbers', fn ($query) => $query->where('provider_phone_id', $data['provider_phone_id']))
                ->update(['is_active' => false, 'status' => 'disabled']);
            // Changing the number must not silently reset the restaurant's
            // greeting words, order types, approval and catalog messages.
            $previousCapabilities = (array) (WhatsAppTenantAssignment::query()->where('tenant_id', $tenantId)
                ->latest('is_active')->latest('id')->value('capabilities') ?? []);
            WhatsAppTenantAssignment::query()->where('tenant_id', $tenantId)->update(['is_active' => false, 'suspended_at' => now()]);
            $profile = WhatsAppProviderProfile::query()->create([
                'tenant_id' => $tenantId, 'name' => $data['name'], 'ownership_mode' => 'restaurant_owned',
                'provider' => $data['provider'], 'credentials' => $data['credentials'], 'status' => 'pending',
            ]);
            $number = $profile->phoneNumbers()->create([
                'provider_phone_id' => $data['provider_phone_id'], 'display_number' => $data['display_number'], 'status' => 'pending',
            ]);

            return WhatsAppTenantAssignment::query()->create([
                'tenant_id' => $tenantId, 'provider_profile_id' => $profile->id, 'phone_number_id' => $number->id,
                'ownership_mode' => 'restaurant_owned', 'allowed_branch_ids' => $branchIds->all(),
                'capabilities' => array_replace(
                    array_is_list($previousCapabilities) ? [] : $previousCapabilities,
                    ['ordering' => true, 'human_handoff' => (bool) data_get($previousCapabilities, 'human_handoff', true)],
                ), 'is_active' => true,
            ])->load(['profile', 'phoneNumber']);
        });

        return ApiResponse::success((new WhatsAppConnectionResource($assignment))->resolve($request), code: 201);
    }

    public function rotateWebhookSecret(Request $request): JsonResponse
    {
        $assignment = WhatsAppTenantAssignment::query()
            ->with('profile')
            ->where('tenant_id', $this->tenantId($request))
            ->where('is_active', true)
            ->firstOrFail();

        abort_unless($assignment->ownership_mode === 'restaurant_owned', 403, 'Managed connection secrets can only be rotated by the platform administrator.');
        abort_unless($assignment->profile, 409, 'The active WhatsApp provider profile is unavailable.');

        $secret = bin2hex(random_bytes(32));
        $credentials = $assignment->profile->credentials ?: [];
        $credentials['webhook_secret'] = $secret;
        $assignment->profile->update([
            'credentials' => $credentials,
            'credential_version' => ((int) $assignment->profile->credential_version) + 1,
        ]);

        return ApiResponse::success([
            'webhook_secret' => $secret,
            'rotated_at' => now()->toIso8601String(),
            'warning' => 'Copy this secret now. It will not be shown again.',
        ]);
    }

    public function configure(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'approval_mode' => ['required', Rule::in(['manual', 'automatic', 'hybrid'])],
            'enabled_order_types' => ['required', 'array', 'min:1'],
            'enabled_order_types.*' => [Rule::in(['takeaway', 'delivery'])],
            'human_handoff_enabled' => ['required', 'boolean'],
            'payments_enabled' => ['required', 'boolean'],
            'kot_release_policy' => ['required', Rule::in(['immediate', 'after_payment_or_approval'])],
            'greeting_keywords' => ['required', 'array', 'min:1', 'max:30'],
            'greeting_keywords.*' => ['required', 'string', 'max:40', 'regex:/^[\pL\pN _-]+$/u'],
            'catalog_message' => ['required', 'string', 'min:10', 'max:1024'],
            'catalog_footer' => ['required', 'string', 'min:3', 'max:256'],
            'catalog_rejection_message' => ['required', 'string', 'min:10', 'max:1000'],
            'allowed_branch_ids' => ['nullable', 'array'], 'allowed_branch_ids.*' => ['string', 'max:64'],
        ]);
        if ($data['payments_enabled']) {
            $paymentReadiness = $this->paymentGatewayReadiness($tenantId);
            abort_unless($paymentReadiness['ready'], 422, $paymentReadiness['message']);
        }
        $assignment = WhatsAppTenantAssignment::query()->where('tenant_id', $tenantId)->where('is_active', true)->latest('id')->firstOrFail();
        $branches = $this->resolveBranchIds($tenantId, $data['allowed_branch_ids'] ?? []);
        $greetings = collect($data['greeting_keywords'])->map(fn ($keyword) => mb_strtolower(trim($keyword)))
            ->filter()->unique()->values()->all();
        $transition = DB::transaction(function () use ($assignment, $branches, $data, $greetings): array {
            $lockedAssignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()
                ->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $transition = $this->branchTransition->retireRemovedBranches($lockedAssignment, $branches->all());
            $lockedAssignment->update([
                'allowed_branch_ids' => $branches->all(),
                'capabilities' => [
                    'ordering' => true, 'approval_mode' => $data['approval_mode'],
                    'enabled_order_types' => $data['enabled_order_types'], 'human_handoff' => $data['human_handoff_enabled'],
                    'payments' => $data['payments_enabled'],
                    'kot_release_policy' => $data['kot_release_policy'],
                    'greeting_keywords' => $greetings,
                    'catalog_message' => trim($data['catalog_message']),
                    'catalog_footer' => trim($data['catalog_footer']),
                    'catalog_rejection_message' => trim($data['catalog_rejection_message']),
                ],
            ]);

            return $transition;
        });
        foreach ($transition['catalog_mapping_ids'] as $mappingId) {
            SyncWhatsAppCatalogProduct::dispatch($mappingId);
        }
        setting([
            'online_order_kot_release_policy' => $data['kot_release_policy'],
            'whatsapp_order_greeting_keywords' => $greetings,
        ]);

        $message = $transition['removed_branch_ids'] === []
            ? 'WhatsApp ordering settings updated.'
            : 'Branch access updated. Old-branch chats were closed and catalog products are being removed. Sync the new branch catalog before accepting orders.';

        return ApiResponse::success((new WhatsAppConnectionResource($assignment->fresh(['profile', 'phoneNumber'])))->resolve($request), $message);
    }

    public function conversations(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $filters = $request->validate(['state' => ['nullable', Rule::in(['bot', 'waiting_for_staff', 'staff', 'closed'])], 'branch_id' => ['nullable', 'string', 'max:64']]);
        $branchId = isset($filters['branch_id']) ? $this->resolveBranchIds($tenantId, [$filters['branch_id']])->first() : null;
        $paginator = WhatsAppConversation::query()->where('tenant_id', $tenantId)
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->where('state', $state))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['branch', 'messages' => fn ($q) => $q->latest()->limit(1)])->latest('last_message_at')->paginate();

        return ApiResponse::pagination($paginator, WhatsAppConversationResource::class);
    }

    public function webhookEvents(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['queued', 'processed', 'failed'])],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $events = WhatsAppWebhookEvent::query()
            ->withoutGlobalTenant()
            ->where('tenant_id', $tenantId)
            ->with('providerProfile:id,provider,name')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('provider_event_id', 'like', '%'.$search.'%')
                        ->orWhere('event_type', 'like', '%'.$search.'%')
                        ->orWhere('error', 'like', '%'.$search.'%');
                });
            })
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        $events->getCollection()->transform(function (WhatsAppWebhookEvent $event) {
            $payload = collect($event->payload ?? [])->only([
                'type', 'text', 'timestamp', 'provider_phone_id', 'provider_event_id',
                'button_id', 'interactive_id', 'sender_masked',
            ])->filter(fn ($value) => $value !== null && $value !== '')->all();

            return [
                'id' => $event->id,
                'provider' => $event->providerProfile?->provider,
                'connection' => $event->providerProfile?->name,
                'provider_event_id' => $event->provider_event_id,
                'event_type' => $event->event_type,
                'status' => $event->status,
                'request' => $payload,
                'response' => [
                    'accepted' => in_array($event->status, ['queued', 'processed'], true),
                    'processed' => $event->status === 'processed',
                ],
                'failure_reason' => $event->error,
                'received_at' => $event->created_at?->toISOString(),
                'processed_at' => $event->processed_at?->toISOString(),
            ];
        });

        return ApiResponse::pagination($events);
    }

    public function clearWebhookEvents(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['processed', 'failed'])],
        ]);

        // Queued events are deliberately retained because a worker may still
        // be processing them. Cleanup is tenant scoped and never touches
        // another restaurant's diagnostics.
        $statuses = isset($filters['status'])
            ? [$filters['status']]
            : ['processed', 'failed'];
        $deleted = WhatsAppWebhookEvent::query()
            ->withoutGlobalTenant()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', $statuses)
            ->delete();

        return ApiResponse::success([
            'deleted' => $deleted,
            'retained_queued_events' => WhatsAppWebhookEvent::query()
                ->withoutGlobalTenant()
                ->where('tenant_id', $tenantId)
                ->where('status', 'queued')
                ->count(),
        ], 'Webhook logs cleared.');
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        return strlen($digits) <= 4
            ? str_repeat('*', strlen($digits))
            : str_repeat('*', strlen($digits) - 4).substr($digits, -4);
    }

    public function conversation(Request $request, string $uuid): JsonResponse
    {
        $conversation = WhatsAppConversation::query()->where('tenant_id', $this->tenantId($request))->where('uuid', $uuid)->firstOrFail();

        return ApiResponse::success((new WhatsAppConversationResource($conversation->load(['branch', 'messages' => fn ($q) => $q->oldest()])))->resolve($request));
    }

    public function handoff(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['state' => ['required', Rule::in(['bot', 'waiting_for_staff', 'staff', 'closed'])]]);
        $conversation = WhatsAppConversation::query()->where('tenant_id', $this->tenantId($request))->where('uuid', $uuid)->firstOrFail();
        $conversation->update(['state' => $data['state'], 'assigned_user_id' => $data['state'] === 'staff' ? $request->user()->id : null, 'closed_at' => $data['state'] === 'closed' ? now() : null]);

        return ApiResponse::success((new WhatsAppConversationResource($conversation->refresh()->load('branch')))->resolve($request));
    }

    public function orderSessions(Request $request): JsonResponse
    {
        $paginator = WhatsAppOrderSession::query()->where('tenant_id', $this->tenantId($request))->with(['branch', 'order'])->latest()->paginate();

        return ApiResponse::pagination($paginator, WhatsAppOrderSessionResource::class);
    }

    public function approve(Request $request, string $uuid, WhatsAppOrderingEngine $engine): JsonResponse
    {
        $session = WhatsAppOrderSession::query()->where('tenant_id', $this->tenantId($request))
            ->with(['conversation.assignment.profile', 'conversation.assignment.phoneNumber'])
            ->where('uuid', $uuid)->firstOrFail();
        $order = $engine->approve($session);

        return ApiResponse::success(['session' => (new WhatsAppOrderSessionResource($session->fresh()->load(['branch', 'order'])))->resolve($request)]);
    }

    public function deliveryAddress(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'instructions' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $session = WhatsAppOrderSession::query()->where('tenant_id', $this->tenantId($request))->where('uuid', $uuid)->firstOrFail();
        abort_if($session->order_id || in_array($session->state, ['completed', 'cancelled', 'expired'], true), 409, 'This order session can no longer be changed.');
        abort_unless($session->order_type === 'delivery', 422, 'Delivery address applies only to delivery orders.');
        $session->update(['delivery_address' => $data]);

        return ApiResponse::success((new WhatsAppOrderSessionResource($session->fresh()->load(['branch', 'order'])))->resolve($request));
    }

    public function payment(Request $request, string $uuid, DirectUpiService $service): JsonResponse
    {
        $session = WhatsAppOrderSession::query()->where('tenant_id', $this->tenantId($request))->where('uuid', $uuid)->firstOrFail();
        abort_unless($session->order_id, 409, 'Approve the WhatsApp order before creating payment.');
        $assignment = WhatsAppTenantAssignment::query()->where('tenant_id', $session->tenant_id)->where('is_active', true)->firstOrFail();
        abort_unless((bool) data_get($assignment->capabilities, 'payments', false), 403, 'WhatsApp payments are disabled.');
        $payment = $service->create($session->tenant_id, (int) $request->user()->id, $session->order_id, 'whatsapp-'.$session->uuid.'-'.now()->format('YmdHi'), 3);
        $session->update(['state' => 'payment_pending']);

        return ApiResponse::success(['reference' => $payment->reference, 'status' => $payment->status, 'intent_url' => $payment->intent_url, 'qr_data' => $payment->qr_data, 'expires_at' => $payment->expires_at]);
    }

    private function tenantId(Request $request): int
    {
        abort_unless($request->user()?->assignedToTenant(), 403, 'A tenant workspace is required.');

        return (int) $request->user()->tenantId();
    }

    private function catalogProductImageUrl(Product $product): ?string
    {
        try {
            $url = $product->medium_url ?: $product->thumbnail_url ?: $product->original_url;
            if ($url) {
                return $url;
            }
        } catch (Throwable) {
            // Older rows may lack the legacy image column; attached media still works.
        }

        return $product->files->first()?->preview_image_url
            ?: $product->files->first()?->url
            ?: $product->thumbnail?->preview_image_url;
    }

    private function resolveBranchIds(int $tenantId, array $keys): \Illuminate\Support\Collection
    {
        $keys = collect($keys)->map(fn ($key) => trim((string) $key))->filter()->unique()->values();
        if ($keys->isEmpty()) {
            return collect();
        }
        $branches = Branch::query()->withoutGlobalActive()->where('tenant_id', $tenantId)
            ->where(function ($query) use ($keys): void {
                $query->whereIn('uuid', $keys);
                $numeric = $keys->filter(fn ($key) => ctype_digit($key))->map(fn ($key) => (int) $key);
                if ($numeric->isNotEmpty()) {
                    $query->orWhereIn('id', $numeric);
                }
            })->get(['id']);
        abort_unless($branches->count() === $keys->count(), 404, 'One or more branches are unavailable.');

        return $branches->pluck('id')->map(fn ($id) => (int) $id)->values();
    }
}
