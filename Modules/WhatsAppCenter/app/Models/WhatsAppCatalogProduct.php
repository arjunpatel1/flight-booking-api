<?php

namespace Modules\WhatsAppCenter\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\Product;
use Modules\Saas\Traits\BelongsToTenant;
use Modules\Support\Eloquent\Model;

class WhatsAppCatalogProduct extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_catalog_products';
    protected $fillable = ['tenant_id', 'branch_id', 'provider_profile_id', 'provider', 'catalog_id', 'product_id',
        'product_retailer_id', 'provider_product_id', 'payload_hash', 'sync_attempts',
        'status', 'sync_status', 'last_synced_at', 'last_sync_error'];
    protected function casts(): array { return ['last_synced_at' => 'datetime', 'sync_attempts' => 'integer']; }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
