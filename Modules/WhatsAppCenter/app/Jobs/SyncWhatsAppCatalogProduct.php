<?php

namespace Modules\WhatsAppCenter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Modules\Branch\Models\Branch;
use Modules\Product\Models\Product;
use Modules\WhatsAppCenter\Models\WhatsAppCatalogProduct;
use Modules\WhatsAppCenter\Models\WhatsAppTenantAssignment;
use RuntimeException;

class SyncWhatsAppCatalogProduct implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $mappingId)
    {
        $this->onQueue('whatsapp');
    }

    public function uniqueId(): string
    {
        return 'whatsapp-catalog-product:'.$this->mappingId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(15)->expireAfter(180)];
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function handle(): void
    {
        $mapping = WhatsAppCatalogProduct::query()->withoutGlobalTenant()
            ->with('product')->findOrFail($this->mappingId);
        $assignment = WhatsAppTenantAssignment::query()->withoutGlobalTenant()->with('profile')
            ->where('tenant_id', $mapping->tenant_id)
            ->where('provider_profile_id', $mapping->provider_profile_id)
            ->where('is_active', true)->whereNull('suspended_at')->firstOrFail();
        abort_unless($assignment->profile?->provider === 'nexmsg' && $assignment->profile->status !== 'disabled', 409, 'WhatsApp integration is unavailable.');
        $isDisabling = $mapping->status === 'disabled';
        $allowedBranchIds = array_map('intval', $assignment->allowed_branch_ids ?: []);
        abort_unless($isDisabling || $allowedBranchIds === []
            || in_array((int) $mapping->branch_id, $allowedBranchIds, true), 403, 'WhatsApp branch is unavailable.');

        $credentials = $assignment->profile->credentials ?: [];
        abort_unless(hash_equals((string) ($credentials['catalog_id'] ?? ''), (string) $mapping->catalog_id), 409, 'WhatsApp catalog configuration changed.');
        $authKey = trim((string) ($credentials['auth_key'] ?? ''));
        $accountId = trim((string) ($credentials['account_id'] ?? ''));
        abort_unless($authKey !== '' && $accountId !== '', 409, 'NexMsg catalog credentials are unavailable.');

        $branch = Branch::query()->withoutGlobalActive()->withTrashed()->where('tenant_id', $mapping->tenant_id)
            ->whereKey($mapping->branch_id)->firstOrFail();
        $product = Product::query()->withoutGlobalScopes()->withTrashed()->find($mapping->product_id);
        $active = ! $isDisabling && (bool) $branch->is_active && $mapping->status === 'active'
            && $product && ! $product->trashed()
            && (bool) $product->is_active && (bool) $product->is_available
            && $product->menu()->withoutGlobalScopes()
                ->where('menus.branch_id', $branch->id)
                ->where('menus.is_active', true)->whereNull('menus.deleted_at')->exists();
        $imageUrl = $active ? $this->publicImageUrl($product) : null;
        $payload = [
            'account_id' => $accountId,
            'catalog' => ['external_id' => (string) $mapping->catalog_id],
            'status' => $active ? 'active' : 'disabled',
            'product' => [
                'retailer_id' => (string) $mapping->product_retailer_id,
                'name' => $product ? mb_substr((string) $product->name, 0, 200) : 'Unavailable product',
                'description' => $product ? mb_substr(trim((string) $product->description), 0, 5000) : '',
                'price' => $product ? (float) $product->selling_price->amount() : 0,
                'currency' => strtoupper((string) $branch->currency),
                'image_url' => $imageUrl,
                'availability' => $active ? 'in stock' : 'out of stock',
            ],
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if ($mapping->sync_status === 'synced' && hash_equals((string) $mapping->payload_hash, $hash)) {
            return;
        }
        $mapping->update(['sync_status' => 'syncing', 'sync_attempts' => $mapping->sync_attempts + 1, 'last_sync_error' => null]);

        $response = Http::connectTimeout(5)->timeout(30)->withHeaders([
            'authkey' => $authKey,
            'Idempotency-Key' => 'nexdine-catalog:'.$mapping->id.':'.$hash,
            'Accept' => 'application/json',
        ])->asJson()->retry(2, 500, fn (\Throwable $error) => $error instanceof ConnectionException
            || ($error instanceof RequestException && ($error->response->status() === 429 || $error->response->serverError())), throw: false)
            ->post((string) config('whatsappcenter.nexmsg.catalog_sync_url'), $payload);

        $body = $response->json();
        if ($response->failed() || data_get($body, 'success') !== true) {
            $detail = data_get($body, 'error.message') ?: data_get($body, 'error') ?: data_get($body, 'message');
            $detail = is_scalar($detail) ? trim((string) $detail) : '';
            $detail = preg_replace('/(api[_ -]?key|auth[_ -]?key|token|secret|authorization)(["\'\s:=]+)[^,\s}]+/i', '$1$2[redacted]', $detail);
            $mapping->update(['sync_status' => 'failed', 'last_sync_error' => mb_substr(
                "NexMsg catalog sync failed ({$response->status()})".($detail !== '' ? ': '.$detail : '.'), 0, 500,
            )]);
            throw new RuntimeException('NexMsg catalog synchronization failed.');
        }
        $mapping->update([
            'provider_product_id' => data_get($body, 'product.provider_product_id'),
            'payload_hash' => $hash,
            'status' => $active ? 'active' : 'disabled',
            'sync_status' => $active ? 'synced' : 'disabled',
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $mapping = WhatsAppCatalogProduct::query()->withoutGlobalTenant()->find($this->mappingId);
        if (! $mapping) {
            return;
        }
        $mapping->update([
            'sync_status' => 'failed',
            'last_sync_error' => $mapping->last_sync_error ?: 'Catalog synchronization failed. Check the connection and product image, then retry.',
        ]);
    }

    private function publicImageUrl(Product $product): ?string
    {
        $url = trim((string) ($product->original_url ?: $product->medium_url ?: $product->thumbnail_url));
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Product image must use a public HTTPS URL.');
        }

        return $url;
    }
}
