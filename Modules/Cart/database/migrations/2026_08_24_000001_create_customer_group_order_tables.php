<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_group_carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('host_customer_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('cart_id')->unique();
            $table->string('status', 24)->default('active');
            $table->string('order_type', 24)->default('takeaway');
            $table->char('invite_token_hash', 64)->unique();
            $table->char('invite_code_hash', 64)->unique();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('participant_limit')->default(12);
            $table->json('quote_snapshot')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'branch_id', 'status', 'expires_at'], 'group_carts_tenant_state_idx');
            $table->unique(['id', 'tenant_id'], 'group_carts_tenant_owner_unique');
        });

        Schema::create('customer_group_participants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('group_cart_id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('participant');
            $table->string('status', 20)->default('active');
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->foreign(['group_cart_id', 'tenant_id'], 'group_participant_owner_fk')
                ->references(['id', 'tenant_id'])->on('customer_group_carts')->cascadeOnDelete();
            $table->unique(['group_cart_id', 'customer_id'], 'group_participant_customer_unique');
            $table->index(['tenant_id', 'customer_id', 'status'], 'group_participant_customer_idx');
        });

        Schema::create('customer_group_cart_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('group_cart_id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('customer_group_participants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->json('options')->nullable();
            $table->string('note', 300)->nullable();
            $table->decimal('unit_price_snapshot', 14, 4);
            $table->unsignedInteger('cart_version');
            $table->timestamps();
            $table->foreign(['group_cart_id', 'tenant_id'], 'group_item_owner_fk')
                ->references(['id', 'tenant_id'])->on('customer_group_carts')->cascadeOnDelete();
            $table->index(['group_cart_id', 'participant_id'], 'group_item_participant_idx');
        });

        Schema::create('customer_group_cart_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('group_cart_id');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('actor_customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->foreign(['group_cart_id', 'tenant_id'], 'group_event_owner_fk')
                ->references(['id', 'tenant_id'])->on('customer_group_carts')->cascadeOnDelete();
            $table->index(['tenant_id', 'group_cart_id', 'created_at'], 'group_event_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_group_cart_events');
        Schema::dropIfExists('customer_group_cart_items');
        Schema::dropIfExists('customer_group_participants');
        Schema::dropIfExists('customer_group_carts');
    }
};
