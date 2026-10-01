<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->enum('ownership_mode', ['restaurant_owned', 'nexdine_managed']);
            $table->enum('provider', ['meta', 'msg91']);
            $table->json('credentials');
            $table->string('credential_version', 40)->default('v1');
            $table->string('status', 30)->default('pending');
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('webhook_last_received_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'ownership_mode', 'is_active'], 'wa_profiles_tenant_mode_active');
        });

        Schema::create('whatsapp_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained('whatsapp_provider_profiles')->cascadeOnDelete();
            $table->string('provider_phone_id', 191);
            $table->string('display_number', 40);
            $table->string('display_name', 120)->nullable();
            $table->string('status', 30)->default('pending');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['provider_profile_id', 'provider_phone_id'], 'wa_number_profile_provider_unique');
        });

        Schema::create('whatsapp_tenant_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('provider_profile_id')->constrained('whatsapp_provider_profiles')->cascadeOnDelete();
            $table->foreignId('phone_number_id')->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->enum('ownership_mode', ['restaurant_owned', 'nexdine_managed']);
            $table->json('allowed_branch_ids')->nullable();
            $table->json('capabilities')->nullable();
            $table->unsignedInteger('monthly_message_limit')->nullable();
            $table->unsignedInteger('monthly_order_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'phone_number_id'], 'wa_assignment_tenant_number_unique');
            $table->index(['phone_number_id', 'is_active'], 'wa_assignment_number_active');
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('assignment_id')->constrained('whatsapp_tenant_assignments')->cascadeOnDelete();
            $table->string('customer_phone', 40);
            $table->string('customer_name', 160)->nullable();
            $table->enum('state', ['bot', 'waiting_for_staff', 'staff', 'closed'])->default('bot');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id', 'state', 'last_message_at'], 'wa_conversations_tenant_queue');
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('provider_message_id', 191)->nullable();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('type', 30)->default('text');
            $table->text('body')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('received');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'provider_message_id'], 'wa_message_conversation_provider_unique');
            $table->index(['tenant_id', 'status', 'created_at'], 'wa_messages_tenant_status');
        });

        Schema::create('whatsapp_order_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->uuid('cart_uuid');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('order_type', 30)->nullable();
            $table->string('state', 40)->default('browsing');
            $table->json('delivery_address')->nullable();
            $table->decimal('quoted_total', 14, 2)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id', 'state'], 'wa_order_sessions_tenant_state');
        });

        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('provider_profile_id')->constrained('whatsapp_provider_profiles')->cascadeOnDelete();
            $table->string('provider_event_id', 191);
            $table->string('event_type', 80);
            $table->char('payload_hash', 64);
            $table->json('payload');
            $table->string('status', 30)->default('received');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['provider_profile_id', 'provider_event_id'], 'wa_webhook_profile_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
        Schema::dropIfExists('whatsapp_order_sessions');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_tenant_assignments');
        Schema::dropIfExists('whatsapp_phone_numbers');
        Schema::dropIfExists('whatsapp_provider_profiles');
    }
};
