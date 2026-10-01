<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_catalog_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('provider_profile_id')->constrained('whatsapp_provider_profiles')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('catalog_id', 191);
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('product_retailer_id', 191);
            $table->string('status', 30)->default('active');
            $table->string('sync_status', 30)->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamps();
            $table->unique(['provider_profile_id', 'catalog_id', 'product_retailer_id'], 'wa_catalog_profile_retailer_unique');
            $table->unique(['provider_profile_id', 'catalog_id', 'product_id'], 'wa_catalog_profile_product_unique');
            $table->index(['tenant_id', 'branch_id', 'status'], 'wa_catalog_tenant_branch_status');
        });
        Schema::table('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->string('provider_order_id', 191)->nullable()->after('provider_event_id');
            $table->unique(['provider_profile_id', 'provider_order_id'], 'wa_webhook_profile_order_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->dropUnique('wa_webhook_profile_order_unique');
            $table->dropColumn('provider_order_id');
        });
        Schema::dropIfExists('whatsapp_catalog_products');
    }
};
