<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_app_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('app_enabled')->default(true);
            $table->boolean('sliders_enabled')->default(true);
            $table->boolean('offers_enabled')->default(true);
            $table->boolean('events_enabled')->default(true);
            $table->boolean('customer_registration_enabled')->default(true);
            $table->boolean('guest_checkout_enabled')->default(true);
            $table->boolean('delivery_enabled')->default(false);
            $table->boolean('pickup_enabled')->default(true);
            $table->boolean('dine_in_enabled')->default(true);
            $table->boolean('table_qr_enabled')->default(true);
            $table->boolean('order_tracking_enabled')->default(true);
            $table->boolean('notifications_enabled')->default(true);
            $table->string('contact_phone', 40)->nullable();
            $table->string('whatsapp_number', 40)->nullable();
            $table->string('address_line', 500)->nullable();
            $table->string('map_url', 2048)->nullable();
            $table->json('social_links')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('content_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_app_settings');
    }
};
