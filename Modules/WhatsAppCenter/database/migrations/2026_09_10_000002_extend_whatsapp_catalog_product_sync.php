<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_catalog_products', function (Blueprint $table): void {
            $table->string('provider_product_id', 191)->nullable()->after('product_retailer_id');
            $table->string('payload_hash', 64)->nullable()->after('provider_product_id');
            $table->unsignedSmallInteger('sync_attempts')->default(0)->after('sync_status');
            $table->index(['tenant_id', 'sync_status', 'updated_at'], 'wa_catalog_tenant_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_catalog_products', function (Blueprint $table): void {
            $table->dropIndex('wa_catalog_tenant_sync_status');
            $table->dropColumn(['provider_product_id', 'payload_hash', 'sync_attempts']);
        });
    }
};
